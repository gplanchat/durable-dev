# Workflow recovery and replay strategy

ADR007-workflow-recovery
===

Introduction
---

This **Architecture Decision Record** defines how Durable workflows resume when execution is interrupted or fails. The strategy relies on event sourcing and deterministic replay already implemented in the engine.

Context
---

Durable workflows must survive:

- Process crashes
- Worker restarts
- Transient activity failures
- Timeouts and network unavailability

Resume must preserve data consistency and avoid duplicate side effects (idempotence).

Current strategy: event sourcing and replay
---

### Principle

The event stream (`EventStore::readStream`) is the workflow’s **implicit checkpoint**. On each resume, the workflow is **replayed** by reading events in order. Results of already completed activities are taken from `ActivityCompleted` / `ActivityFailed` events, without re-execution.

### Deterministic slots

Each durable operation (activity, timer, side effect, child, signal, update, …) is tied to a **slot** (sequential index per operation family) on **`ExecutionContext`**. In practice, class-based workflows use **`WorkflowEnvironment`** (`await` on activity stubs, `timer` / `delay`, `sideEffect`, `executeChildWorkflow`, etc.), which delegates to the context.

On replay, `findReplay*ForSlot()` methods determine whether the slot already has a recorded result in the log. For activities completed with a **`null`** result, the engine uses `array_key_exists` so “missing completion” is not confused with “completed with null” (see [ADR010](ADR010-temporal-parity-events-and-replay.md)).

### Guarantees

- **Determinism**: workflow code must not contain non-deterministic I/O or sources (random, date, etc.) **outside** activities or explicit **side effects**
- **Side effects**: any non-deterministic value must go through **`WorkflowEnvironment::sideEffect()`** (or `ExecutionContext::sideEffect()` for low-level handlers) — result is logged, not re-executed on replay — see [ADR010](ADR010-temporal-parity-events-and-replay.md)
- **Idempotence**: activities must be idempotent

Distributed mode: workflow re-dispatch
---

When workflows and activities run in separate processes, resume relies on **re-dispatching** the workflow after asynchronous progress (activity, timer, etc.). See [ADR009](ADR009-distributed-workflow-dispatch.md).

### Implemented (Messenger)

1. **`WorkflowRunMessage`**: start or resume a run (`WorkflowRunHandler` + **`WorkflowMetadataStore`** for type/payload across messages).
2. On distributed **suspension** (`WorkflowSuspendedException` with `shouldDispatchResume()`), **`MessengerWorkflowResumeDispatcher`** enqueues a new resume (often with **`DispatchAfterCurrentBusStamp`**).
3. Activities leave via **`ActivityTransportInterface`** (e.g. Messenger transport **`durable_activities`**).
4. **Timers**: **`FireWorkflowTimersMessage`** + handler calling **`ExecutionRuntime::checkTimers`** and re-dispatching if needed.
5. On **replay** for the same `executionId`, the log supplies already recorded results.

### Inline mode (non-distributed Messenger)

Workflow and activities in the same process (**`InMemoryWorkflowRunner`**, **`drainActivityQueueOnce`**). No re-dispatch; after a crash, restarting the run replays from the log.

### Possible extensions

- Operational hardening (handler idempotence, metrics, DLQ).
- Backoff / retry policies at Messenger transport level (outside the Durable bundle).

References
---

- [RUNTIME-RFC033 - Workflow Recovery](../../architecture/runtime/rfcs/RUNTIME-RFC033-workflow-recovery-and-resume-strategy.md)
- [ADR005 - Messenger integration](ADR005-messenger-integration.md)
- [PRD001 - Current state](../prd/PRD001-current-component-state.md)
- [ADR010 - Temporal parity, events and replay](ADR010-temporal-parity-events-and-replay.md)
