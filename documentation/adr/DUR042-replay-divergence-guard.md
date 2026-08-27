# DUR042 — The replay divergence guard

## Status

Accepted

## Context

DUR003 described this guard as existing:

> `WorkflowTaskRunner` enforces determinism by: […] 2. Comparing the awaitable type and identity at
> each suspension point against the history record for that slot — 3. Raising a non-determinism
> error if a mismatch is detected

It did not. `WorkflowTaskRunner` is 150 lines and contained no comparison and no such error. What
existed was a **positional** lookup, five independent counters deep in `ExecutionContext`:
`activity()` took an activity name and never compared it to what history recorded at that index,
and neither did `nexusOperation()` with its endpoint, service and operation.

The consequence was measured against `temporal server start-dev` 1.31.2, not inferred. A run was
started on code that scheduled `double` at activity slot 0, suspended on a timer, and resumed by a
worker running code that scheduled `append` there instead. The workflow asked for a string and
received `42` — the recorded result of the other call — and then **completed successfully**:

```
result:  ['variant' => 'v2', 'slot0' => 42]
history: … ACTIVITY_TASK_COMPLETED, …, TIMER_FIRED, WORKFLOW_EXECUTION_COMPLETED
```

The server raised nothing; nothing in history marked the divergence. This is the failure mode this
codebase treats as the most expensive, and DUR036 refused a silent fallback for exactly this
reason. Here it was not a design choice — it was a guard the ADR claimed and the code lacked.

## Decision

**Replay compares identity before it resolves a slot.** When a workflow schedules a step, the
engine reads the identity recorded at that position and refuses to resolve when the two differ.

### The identity is what history already holds

| Slot kind | Identity compared |
|---|---|
| Activity | the activity name |
| Nexus operation | the **triple** — endpoint, service, operation |
| Child workflow | the workflow **type** |
| Timer | none available — see below |
| Side effect | none by design; the closure is not reproducible, which is why the value is recorded |

No event field was added. A new field would be absent from older histories, which would leave
without a guard exactly the executions the guard exists to protect.

For Nexus the whole triple counts: routing the same service and operation to a different endpoint
is a different call, and comparing the operation name alone would let it through. For a child
workflow the type counts and the execution id does not — that id is generated, so comparing it
would make a faithful replay diverge every time.

### Absent identity is not divergence

`null` means the journal said nothing at that position: either the slot is new — a workflow that
grew — or the entry carries no such identity. Both must resolve as before. An empty string is not
an identity, and both history sources normalise it to `null` rather than leaving the rule to
callers.

**Timers are therefore uncovered, and this is stated rather than fixed.** `TimerScheduled` records
`clock() + delay`, an absolute due date a replay cannot recompute, and the original delay is
recorded nowhere; `summary` is author-supplied and optional. A guard resting on it would cover only
labelled timers and would fire on a relabel, which is not a divergence.

### The failure is the task, not the run

A divergence is a deployment mistake, and deployment mistakes get reverted. Failing the run would
make the revert useless: the run would already be dead.

So the guard raises `WorkflowTaskFailure`, which the Temporal bridge answers with
`RespondWorkflowTaskFailed` and **no command at all** — history learns nothing of the attempt, the
server redelivers the task, and restoring the code that wrote the history lets the run finish.
Measured: `WORKFLOW_TASK_FAILED` → task retried → `WORKFLOW_EXECUTION_COMPLETED`.

This path did not exist either. `RespondWorkflowTaskFailed` was a generated gRPC stub called
nowhere, and every throw from workflow code became a `FailWorkflowExecution` command.

## Consequences

- **Latent breakage becomes visible.** A deployment that was silently corrupting in-flight runs now
  stops them. That is the point, and it will surface breakage that was already happening.
- **Backends with no notion of a task** treat `WorkflowTaskFailure` like any other exception: there,
  the guard converts silent corruption into a failed run. Better than the alternative, and not the
  revert-and-resume story.
- **The cost on the normal path** is one lookup per slot: **+26 %** on a replay of 400 completed
  activity slots, measured on the journal backend. An unchanged workflow compares equal everywhere
  and behaves exactly as before.
- **Replaying a long history is quadratic in the number of slots, and was already** — every slot
  lookup in `EventStoreHistorySource` re-reads the whole stream. Doubling the slots quadruples the
  time with the guard and without it. The guard adds a constant factor to that, it does not create
  it. Memoising the read is a change to the history source, not to this decision, and it would help
  the four lookups that predate the guard more than the guard itself.
- **DUR003 is corrected**: it described points 2 and 3 as implemented, and they were not.

## Alternatives considered

- **Adding a slot identity to every scheduling event.** Rejected: old histories have no such field,
  so the guard could not check the runs it exists to protect.
- **Validating the command buffer after the fiber completes.** Cheaper to write, and too late — the
  wrong value has already entered the workflow's own variables, and it may have branched on it.
- **Warning instead of failing.** A worker-log warning is read once someone is already investigating
  corrupt data, which is the situation the guard exists to prevent.
- **Hashing the call site (file and line).** Fires on refactors that move a line and are not
  divergences at all.
- **Comparing the child workflow execution id** rather than its type. It is generated per run, so a
  faithful replay would diverge every time.

## Related decisions

- **DUR003** — replay and awaitables. Its determinism section described this guard as existing; it
  is amended to point here.
- **DUR027** — the `WorkflowTaskRunner` algorithm, where the missing comparison was supposed to live.
- **DUR036** — Nexus caller-only, and the rule this decision follows: refuse out loud rather than
  leave a workflow waiting on something that will never come.
- **Workflow versioning** — the sanctioned exception to this guard, and the change that comes next.
  A version marker is precisely where the code is allowed to differ from an older history.
