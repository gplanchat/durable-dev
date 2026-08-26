## Context

The component runs workflows as PHP fibers replayed from history, with two backends behind one
fiber driver (`WorkflowFiberDriver`) and one lifecycle port. Every orchestration primitive already
follows the same shape:

1. a method on `WorkflowCommandBufferInterface` that the in-memory backend journals and the
   Temporal backend turns into a protobuf command;
2. a positional **slot** family in `ExecutionContext` (`activitySlotIndex`, `timerSlotIndex`,
   `childWorkflowSlotIndex`, …) plus a `find…SlotResult()` on `WorkflowHistorySourceInterface`;
3. an awaitable wrapper carrying the identifier, so the fiber driver can cancel it.

Nexus fits this shape exactly on the caller side. What was verified before writing this:

- the generated API is complete — commands, the nine history events, 59 messages, worker and
  operator RPCs — so nothing needs regenerating;
- the local dev server (Temporal 1.31.2) supports Nexus: an endpoint was created and deleted;
- `ScheduleNexusOperationCommandAttributes` exposes `Endpoint`, `Service`, `Operation`, `Input`,
  `NexusHeader` and the three timeouts — the same bound vocabulary as activities;
- `NexusOperationStartedEventAttributes` carries an `OperationToken`, which is what makes
  asynchronous operations different from activities.

## Goals / Non-Goals

**Goals:**

- A workflow can call a Nexus operation and await its result, with replay determinism equal to
  that of activities.
- Failures are classified rather than flattened, as `WorkflowExecutionFailed` kinds already are.
- The in-memory backend fails loudly instead of pretending.
- Synchronous operations first: schedule, await, resolve.

**Non-Goals:**

- **Serving** Nexus operations (the handler side). It needs a third worker loop on
  `PollNexusTaskQueue`, a Nexus service/operation registry distinct from `WorkflowRegistry` and
  `RegistryActivityExecutor`, and the asynchronous completion protocol. None of it has an
  equivalent in the current code. Separate change.
- **Asynchronous operations** where the handler returns a token and completes later via callback.
  The closest existing pattern is the deferred child workflow (`ChildWorkflowStartDeferred`), so
  the ground is prepared, but it is a second increment.
- Managing endpoints (`CreateNexusEndpoint` and siblings). Endpoints are operator concerns,
  created with the `temporal` CLI or by an operator API; the component consumes them.
- Any in-memory simulation of Nexus.

## What was probed, and what was assumed

**Endpoint names, probed against Temporal 1.31.2 (task 1.1).** Every verdict below was observed by
`CreateNexusEndpoint`, not inferred:

| Case | Verdict |
|---|---|
| `""` | refused — `endpoint name not set` |
| `" "`, leading/trailing space, inner tab or newline, control character | refused — regex |
| `_`, `.`, `/`, accented letter | refused — regex |
| leading digit, leading hyphen, trailing hyphen | refused — regex |
| single letter `a` | refused — regex (the pattern needs a first *and* a last character) |
| `ab`, `Probe-Nexus-42` (letters, digits, inner hyphens, either case) | accepted |
| 200 characters | accepted |
| 201 characters | refused — `endpoint name exceeds length limit of 200` |

The server states its own rule: `^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$`, 200 characters.

**This inverts the lesson of `TaskQueue`, and §2.1 must not copy it.** `TaskQueue` is deliberately
*stricter than the server*, because the server accepts `" "` and edge whitespace while a misnamed
queue fails silently — the work is queued and no worker ever comes. A Nexus endpoint has no such
failure mode: the name is validated at creation, and a malformed one is refused outright. So
`NexusEndpoint` mirrors the server's rule and invents nothing on top of it. The one distinction
worth keeping is the server's own: an empty name is *unset*, not *malformed*, and the two deserve
different messages.

Pinned by `tests/integration/Temporal/NexusEndpointNameRulesTest.php`, so a change of server rule
is caught rather than discovered inside a value object.

**Scheduling on an unknown endpoint, probed (task 1.2) — and it contradicts a premise of the
proposal.** The proposal says Nexus failures surface to the workflow as typed failures. An unknown
endpoint is not among them, and never becomes one:

- `RespondWorkflowTaskCompleted` is rejected with gRPC `INVALID_ARGUMENT` (3),
  `BadScheduleNexusOperationAttributes: endpoint "…" not found`;
- history records `WORKFLOW_TASK_FAILED`, cause
  `WORKFLOW_TASK_FAILED_CAUSE_BAD_SCHEDULE_NEXUS_OPERATION_ATTRIBUTES`;
- the workflow task is **re-served**, its `attempt` climbing on every round — measured to 4 and
  still going;
- no `NEXUS_OPERATION_SCHEDULED` is ever written, so there is no operation to fail.

The workflow does not fall over. It spins, and the only trace is a task-failure cause the workflow
code never sees. A validated `NexusEndpoint` value object does **not** cover this: the name is
well formed, it is the endpoint that is missing. Either the caller checks the endpoint exists
before emitting the command, or this loop is accepted knowingly and documented as the failure mode
of a misconfigured endpoint. That choice belongs to §3, not to the value objects of §2.

Pinned by `tests/integration/Temporal/NexusUnknownEndpointTest.php`.

**Service and operation names, probed (task 1.1, second half) — and the rule is the opposite of the
endpoint's.** They travel in the `ScheduleNexusOperation` command, so measuring them needs a real
endpoint and a completed workflow task. Against Temporal 1.31.2, **the server validates neither**:
empty, a single space, edge whitespace, an inner tab, a control character, a slash, an accent, a
thousand characters — every one accepted, and `NEXUS_OPERATION_SCHEDULED` records the name
**verbatim** (measured: `service: ""` and `service: "sv\tc"` read back unchanged).

Nothing follows. The operation sits scheduled, waiting for a handler whose service and operation
will never match, and no error is ever written.

**So the three names of one command follow three different rules, and §2.1 cannot treat them as a
block:**

| Name | Server | What the value object must do |
|---|---|---|
| endpoint | strict — regex, 200 chars, refused at creation | mirror the server, invent nothing |
| service | accepts anything | be **stricter than the server**, like `TaskQueue` |
| operation | accepts anything | be **stricter than the server**, like `TaskQueue` |

The `TaskQueue` reasoning applies verbatim to the last two: the server accepts what cannot be
anything but a mistake, and the mistake costs an execution that waits forever with nothing in the
logs. It does not apply to the endpoint, whose failure is loud and immediate.

Pinned by `tests/integration/Temporal/NexusServiceAndOperationNameRulesTest.php`.

## Decisions

**Model a Nexus operation as its own awaitable family, not as an activity.**
Reusing the activity slot family would be cheaper but wrong: the two would share a positional
counter, so adding a Nexus call would shift every subsequent activity slot and break replay of
in-flight workflows. A separate family costs one counter and one lookup.

**Identify the operation by its scheduled event id.**
`NexusOperationCompleted`, `Failed`, `Canceled` and `TimedOut` all reference `ScheduledEventId`,
exactly like activity events. `TemporalExecutionHistory` already keeps
`scheduledEventIdToActivityId`; the Nexus map is the same pattern. This also gives
`RequestCancelNexusOperation` the real event id it needs — the mistake already fixed once for
`REQUEST_CANCEL_ACTIVITY_TASK`, where a locally invented counter meant the command was never
emitted.

**Refuse Nexus on the in-memory backend rather than emulate it.**
Nexus crosses a namespace boundary by design. An in-process emulation would be a different
protocol wearing the same name, and would make the harness lie about what production does. This is
the first deliberate asymmetry between the two backends, and it is stated in the spec rather than
discovered at runtime.

**Validate identifiers locally, but only what the server would also refuse or what can only be a
mistake.**
The established discipline in this codebase is to probe the server before writing an invariant: it
has already corrected six assumptions (the cron `?` wildcard, cron reachability, a task-timeout cap
that does not exist, the silent run-timeout rewrite, three system search attributes that *are*
writable, and the ignored payload type). Nexus identifier rules MUST be probed the same way before
`NexusEndpoint` and friends encode any rule.

## Risks / Trade-offs

- **Slot ordering is load-bearing.** Adding a slot family is safe for existing workflows, but a
  future change that reorders or merges families would break replay of in-flight executions. The
  family is positional, like the five that exist.
- **Asynchronous operations are deferred, and the boundary may not hold.** If a handler completes
  an operation asynchronously, the caller sees `NEXUS_OPERATION_STARTED` with a token and no
  result. The synchronous-only increment must fail clearly in that case rather than hang — this is
  a scenario to write, not to assume.
- **Integration testing needs an endpoint.** The test must create a Nexus endpoint targeting a task
  queue served by our own worker, which means the integration suite gains a setup step beyond
  `temporal server start-dev`. The search-attribute suite already set this precedent by requiring
  two registered attributes.
- **No handler side means no round-trip test of the whole feature.** Until the handler exists, the
  caller can only be tested against an endpoint backed by something else — our own workflow worker
  acting as the target, or an external stub.
