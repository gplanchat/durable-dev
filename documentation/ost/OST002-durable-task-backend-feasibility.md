# OST002 — Durable Task / Dapr as a Durable backend: feasibility

## Status

Exploration — **leaning against**. The maintainer's reading after this study is that the
contraindications outweigh the fit. Not a decision: closing the option formally would need an ADR,
and nothing here forces one.

Deepens [OST001](OST001-alternative-durable-execution-backends.md) §3, which identified Durable Task
as the best *structural* match of the engines surveyed. This document is what happened when that
match was checked feature by feature.

## Opportunity

Durable Task Framework — the engine under Azure Durable Functions and, via `dapr/durabletask-go`,
under Dapr Workflow — is the only surveyed engine whose worker protocol has the same shape as
Durable's own interpreter: a history of events in, a list of actions out.

```
rpc GetWorkItems(GetWorkItemsRequest) returns (stream WorkItem);
rpc CompleteOrchestratorTask(OrchestratorResponse) returns (CompleteTaskResponse);
rpc CompleteActivityTask(ActivityResponse) returns (CompleteTaskResponse);
```

`OrchestratorRequest` carries `pastEvents` + `newEvents`; `OrchestratorResponse` carries `actions`
and a `completionToken`. Replay in, commands out — the contract `WorkflowFiberDriver` already
implements. One bridge would reach three hosts: the Dapr sidecar, Azure Durable Task Scheduler, and
self-hosted `durabletask-go` over SQLite or Postgres. The proto is public and explicitly meant for
third-party SDKs.

That is the opportunity. The rest of this document is why it is probably not enough.

## Method

Everything below was read from
[`microsoft/durabletask-protobuf`](https://github.com/microsoft/durabletask-protobuf)
`protos/orchestrator_service.proto` (911 lines) and from Durable's own port implementations — not
from documentation or from an SDK's behaviour. Where upstream documentation is the source, it is
quoted.

---

## 1. First, the correction

An earlier reading of this option called per-operation cancellation a **determinism** problem: an
activity that keeps running would land its `TaskCompletedEvent` in history after Durable had already
rejected that slot, so replay would see a completion where the previous task saw a cancellation, and
the compensation branch would flip between tasks.

That is wrong, and the code says so. `EventStoreHistorySource::findActivitySlotResult()` resolves a
slot in this order:

1. catastrophic failure
2. failure
3. **cancellation**
4. completion

Cancellation wins over a completion unconditionally, wherever the two sit in the stream.
`findTimerSlotResult()` does the same — `WORKFLOW_CANCELLED` short-circuits before completion is
even consulted. A late completion cannot flip the branch, provided the bridge's own history source
applies the same precedence, which it can because the bridge writes it.

So cancellation is a **semantic** reduction, not a correctness break. That matters: it moves the
option from "impossible" to "possible, at a price", and the price is what the rest of this document
weighs.

---

## 2. Contraindications that change the contract

### 2.1 No per-operation cancellation

The `OrchestratorAction` oneof has exactly eight members: `scheduleTask`, `createSubOrchestration`,
`createTimer`, `sendEvent`, `completeOrchestration`, `terminateOrchestration`, `sendEntityMessage`,
`rewindOrchestration`. `grep -i cancel` over the whole proto returns **one** hit, and it is the
`ORCHESTRATION_STATUS_CANCELED` enum value. `TerminateOrchestrationAction` kills the instance, which
is the opposite of what a compensation needs.

Upstream states the design plainly: the cancellation mechanism "doesn't terminate in-progress
activity function or sub-orchestration executions; rather, it simply lets the orchestrator function
ignore the result and move on."

**Parade.** Keep cancellation entirely inside the interpreter. Durable already journals
`ActivityCancelled` / `TimerCancelled` as its own events; on Durable Task those become self-addressed
markers. The workflow-facing contract is preserved.

**Residual cost — this is the one that hurts.** The activity **runs to completion**. Its side effects
happen. Every saga compensation must be idempotent *and* tolerate that the operation it compensates
succeeded in the meantime. On Temporal, `REQUEST_CANCEL_ACTIVITY_TASK` gives a chance to stop it;
here there is none.

### 2.2 Side effects: a channel choice with no good option

No marker or side-effect event exists in the protocol. Two channels, and they do not cost the same:

| | `SendEventAction` to self | dedicated `ScheduleTaskAction` |
|---|---|---|
| Ordering | **assumed** — nothing in the proto guarantees it for several events emitted in one action list | **guaranteed** by `taskId` correlation |
| Execution | inside the fiber, in-process | on an **activity worker**, out of process |

`ExecutionContext::sideEffect()` runs the closure and resolves the `Deferred` **immediately**, with
no suspension. `$wf->sideEffect(fn () => $this->clock->now())` captures workflow-local state. The
second option buys ordering determinism by moving execution out of the process, which breaks the
primitive's meaning.

So the real question is not *which channel* but: **can a side effect that must run in-process be
recorded through a protocol that only records out-of-process work?** `SendEventAction` is the
better answer, conditional on proving empirically that a backend preserves the relative order of
several self-addressed events emitted in one action list. That proof does not exist yet, and the
protocol does not owe it to us.

### 2.3 `carryoverEvents` corrupts markers at continue-as-new

`CompleteOrchestrationAction.carryoverEvents` exists for continue-as-new. Upstream semantics:
incomplete task results are **discarded**, while **unprocessed external events** are preserved —
by default in the .NET SDK.

If markers ride `SendEventAction`, they *are* external events. A marker in flight when
continue-as-new fires would be carried into the new run, whose side-effect slot counter restarts at
zero. Silent journal corruption.

**Parade.** Never populate `carryoverEvents`, or filter a reserved name prefix. The bridge builds
the action, so this is controllable — but it has to be a deliberate decision, and it is the kind of
detail that is only obvious once it has already broken something.

---

## 3. Capabilities that would simply be lost

| Capability | Durable Task | Parade |
|---|---|---|
| **Workflow updates** (`findUpdateForSlot`) | nothing | **None.** `RaiseEvent` is one-way. Signal + reply-signal loses the request/response contract. |
| **Activity heartbeats** | `ActivityRequest` is name / version / input / instance / taskId / tags. Nothing. | **None viable.** `ActivityHeartbeatSenderInterface` is not implementable. |
| **Queries** | `customStatus`, a one-way blob | An approximate projection. No arbitrary query. |
| **Search attributes** | `tags map<string, string>` + `QueryInstances` | Enough to filter, not to index and query the way Temporal does. |
| **Cron schedules** | `scheduledStartTimestamp`, `WORKER_CAPABILITY_SCHEDULED_TASKS` | Chain it ourselves through continue-as-new, `CronSchedule` computing the next due time. Workable. |
| **`ParentClosePolicy`** | nothing | Emit `TerminateOrchestrationAction` on children when the parent closes. Workable. |
| **Namespaces** | one task hub (`CreateTaskHub` / `DeleteTaskHub`) | Prefix `instanceId`, or one task hub per namespace. |

Two of these — updates and heartbeats — have no parade at all. They would be the first Durable
capabilities that a backend simply does not have.

---

## 4. What is only work, not loss

Filed here after being initially mis-ranked as gaps:

- **Activity retries.** Zero occurrences of `retry` in the proto, because in Durable Task retrying is
  the *orchestrator's* job: timer plus reschedule. Our interpreter can do exactly that, and
  `TaskFailureDetails.isNonRetriable` carries the classification. Only `ActivityRetryState::InProgress`
  disappears — it exists solely because Temporal retries server-side. A simplification, not a gap.
- **Activity timeouts.** Same shape: a timer racing the task, the standard Durable Task pattern. Only
  `schedule_to_start` is inexpressible.
- **Task queues.** No queues, but `WorkItemFilters` with `OrchestrationFilter` / `ActivityFilter` by
  **name and version**: a worker chooses what it receives. Not queue routing, but it delivers worker
  specialisation.
- **`WorkflowIdReusePolicy`.** `OrchestrationIdReusePolicy.replaceableStatus` is a list of replaceable
  statuses — translatable from our enum.
- **Entity and lock events** (a third of the `HistoryEvent` oneof). Ignore them cleanly on replay.
  Tolerating unknown event types is required anyway to survive protocol evolution.

---

## 5. What would actually be in our favour

Recorded so the ledger is honest, not only the debit side.

- **`tags` is a general-purpose channel.** A `map<string, string>` sits on `ScheduleTaskAction`,
  `CreateSubOrchestrationAction`, `ExecutionStartedEvent` **and** `ActivityRequest`, and it round-trips
  into the matching history events. Everything the protocol does not model — `ActivityOptions`,
  timeouts, retry policy, a logical task queue, `ParentClosePolicy` — can travel there deterministically,
  with no emulation. `TaskFailureDetails.properties` (`map<string, Value>`) offers the same on the
  failure side, alongside a chained `innerFailure` and `isNonRetriable`. Most of §4 dissolves into
  "pass our value objects through `tags`".
- **No repeated history I/O.** `OrchestratorRequest` delivers `pastEvents` + `newEvents` **inside the
  work item**. The bridge would build its `WorkflowHistorySourceInterface` over an in-memory history,
  where `EventStoreHistorySource` re-reads the whole stream on every slot lookup. Plus
  `WORKER_CAPABILITY_HISTORY_STREAMING` / `StreamInstanceHistory` for large histories.
- **The core mapping is clean.** Scheduling, timers, child workflows, completion, failure and
  continue-as-new (`CompleteOrchestrationAction` + `ORCHESTRATION_STATUS_CONTINUED_AS_NEW`) all map
  without tricks.

---

## 6. Why this leans against

Count the contraindications by kind, not by number:

- **One that no documentation fixes.** `cancelActivity()` would compile, run, and do something else.
  The workflow sees its cancellation; the activity runs to completion anyway. Nothing fails, nothing
  warns, and the difference surfaces only in the side effects of a compensation that ran against an
  operation that had already succeeded.
- **Two capabilities with no parade** — updates and heartbeats.
- **Two silent-corruption traps** — self-addressed event ordering, and `carryoverEvents` at
  continue-as-new. Both are controllable; both are invisible until they are not.
- **A long tail of work** that is real but uninteresting: retries, timeouts, policies, filters,
  entity-event tolerance.

The user documentation states a principle: *"Where a capability is missing it **fails explicitly**
rather than being silently ignored."* That principle holds for updates, heartbeats and queries — we
would throw. It does **not** hold for cancellation, and cancellation is not a peripheral feature: it
is the backbone of the saga and compensation story the documentation currently sells, diagrams
included.

A backend that quietly weakens the guarantee its own documentation advertises is worse than no
backend.

## 7. Hypotheses that would have to be answered first

Kept because they are what a reversal would need, not because the answers are expected to change the
verdict.

1. Does a real backend (`durabletask-go`, Dapr sidecar) preserve the relative order of several
   self-addressed `SendEventAction`s emitted in one action list? Everything about side effects rests
   on this.
2. Is a durable-execution backend without workflow updates and without heartbeats worth shipping at
   all, given the two others have both?
3. Would users accept "cancel means abandon-and-ignore, write idempotent compensations" as a
   documented per-backend contract — or does per-backend semantics for cancellation break the promise
   that the authoring model is the same everywhere?

## 8. Position

**Contraindicated.** Not impossible, not even hard in most places, but the one thing it silently
changes is the one thing Durable sells hardest.

If the appetite for a third protocol-level backend persists, [OST001](OST001-alternative-durable-execution-backends.md)
§3 puts **Restate** next: it removes `ext-grpc` entirely and fits PHP-FPM, at the cost of protocol
churn. That is a risk that shows up in CI, not in a customer's compensation.

## References

- [`microsoft/durabletask-protobuf` — `orchestrator_service.proto`](https://github.com/microsoft/durabletask-protobuf)
- [`dapr/durabletask-go`](https://github.com/dapr/durabletask-go) · [Dapr Workflow architecture](https://docs.dapr.io/developing-applications/building-blocks/workflow/workflow-architecture/)
- [Durable Task timers and cancellation semantics](https://learn.microsoft.com/en-us/azure/durable-task/common/durable-task-timers)
- [Eternal orchestrations and continue-as-new](https://learn.microsoft.com/en-us/azure/durable-task/common/durable-task-eternal-orchestrations)
- [OST001](OST001-alternative-durable-execution-backends.md) — the survey this deepens
- [DUR030](../adr/DUR030-dbal-backend-simplified-durable-execution.md) — the backend that shipped instead
