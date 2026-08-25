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
