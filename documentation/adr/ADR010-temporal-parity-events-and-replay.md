# ADR010 — Events and replay for Temporal parity (workflows)

ADR010-temporal-parity-events-and-replay
===

Introduction
---

This **Architecture Decision Record** inventories **event types** and **replay** behavior needed to cover workflow capabilities documented in [OST004](../ost/OST004-workflow-temporal-feature-parity.md) (alignment with the Temporal PHP SDK).

Context
---

[OST004](../ost/OST004-workflow-temporal-feature-parity.md) lists five families: side effects, durable timers, child workflows, continue-as-new, signals / queries / updates. The Durable engine relies on an **append-only log** and **sequential slots** per operation family (see [ADR007](ADR007-workflow-recovery.md)).

Decision — inventory by capability
---

### 1. Side effects

| Item | Decision |
|------|----------|
| **Events** | `SideEffectRecorded(executionId, sideEffectId, result)` — one event per successful call. |
| **Replay** | Order of `SideEffectRecorded` in the stream = order of `ExecutionContext::sideEffect()` calls; the *slot* is the index in that sub-sequence. The closure is **not** re-run; `result` is read from the log. |
| **Failure** | Exception from closure **before** append → no event; aligned with Temporal (workflow task failure). |
| **API** | `WorkflowEnvironment::sideEffect(Closure): mixed` (via internal await); equivalent on `ExecutionContext` for low-level handlers. |

**Reference**: [Temporal — Side Effects (PHP)](https://docs.temporal.io/develop/php/side-effects).

### 2. Durable timers

| Item | Decision |
|------|----------|
| **Events** | Already: `TimerScheduled`, `TimerCompleted` (unchanged). |
| **Replay** | Slot on the `TimerScheduled` sub-sequence + completion by `TimerCompleted` (current `delay()` behavior). |
| **API** | `WorkflowEnvironment::timer($seconds)` = alias of `delay()`; aligned with Temporal docs. |

**Reference**: [Temporal — Durable Timers (PHP)](https://docs.temporal.io/develop/php/timers).

### 3. Child workflows

| Item | Decision |
|------|----------|
| **Events** | `ChildWorkflowScheduled` (incl. `parentClosePolicy`, `requestedWorkflowId`), `ChildWorkflowCompleted`, `ChildWorkflowFailed` (message/code + optional fields aligned with child `WorkflowExecutionFailed` when projected from async Messenger). |
| **Replay** | Slot on `ChildWorkflowScheduled` sub-sequence; resolution by `ChildWorkflowCompleted` or `ChildWorkflowFailed` for the same `childExecutionId`. Parent **`DurableChildWorkflowFailedException`** replays enriched fields from the log. |
| **Execution** | **Inline**: `ChildWorkflowRunner` + `InMemoryWorkflowRunner` on child log. **Async Messenger** (`child_workflow.async_messenger`): dispatch `WorkflowRunMessage`, parent finalization in `WorkflowRunHandler`, parent↔child link via **`ChildWorkflowParentLinkStoreInterface`** (in_memory or DBAL). |
| **API** | `WorkflowEnvironment::executeChildWorkflow` / **`childWorkflowStub(ChildClass::class)`**; `ChildWorkflowOptions`. See [ADR011](ADR011-child-workflow-continue-as-new.md). |

**Reference**: [Temporal — Child Workflows (PHP)](https://docs.temporal.io/develop/php/child-workflows).

### 4. Continue-as-new

| Item | Decision (target) |
|------|-------------------|
| **Events** | End-of-run marker + start of new run (same logical `executionId` or explicit chaining policy); new run history **empty** for replay purposes. |
| **Replay** | A run replays **only** its own history segment; no mixing with the previous run’s history. |
| **Handlers** | Temporal rule: do not invoke continue-as-new from Update/Signal handlers without synchronization — replicate in product docs. |

**Reference**: [Temporal — Continue-As-New (PHP)](https://docs.temporal.io/develop/php/continue-as-new).

### 5. Signals, queries, updates

| Item | Decision (target) |
|------|-------------------|
| **Events** | `WorkflowSignalReceived`, `WorkflowUpdateHandled` (log); queries = log read via **`WorkflowQueryEvaluator`** / **`WorkflowQueryRunner`** (no parent *QueryCompleted*-style history event). |
| **Replay** | Accepted signals / updates are part of deterministic log; queries must not produce commands (no activity / timer from handler). |
| **API** | PHP mirror attributes (evolution of [OST003](../ost/OST003-activity-api-ergonomics.md)) + client / transport. |

**Reference**: [Temporal — Message passing (PHP)](https://docs.temporal.io/develop/php/message-passing).

Summary — implementation status
---

| Capability | Events / API | Status |
|------------|--------------|--------|
| Side effects | `SideEffectRecorded` + `sideEffect()` | **Implemented** (this ADR) |
| Timers | `TimerScheduled` / `TimerCompleted` + `timer()` | **Timers** OK; `timer()` alias |
| Child workflows | Events above + **`ChildWorkflowStub`** / `executeChildWorkflow` + async Messenger + **`DbalChildWorkflowParentLinkStore`** + rich failure projection (`AsyncChildWorkflowFailureProjector`) | **Partial** (Temporal SDK parity / timeouts / all policies — see [OST004](../ost/OST004-workflow-temporal-feature-parity.md)); **core** log + inline + async + parent exception replay **OK** |
| Continue-as-new | `WorkflowContinuedAsNew` + `ContinueAsNewRequested` + `WorkflowRunHandler` | **Partial** (new `executionId`) |
| Signals / Queries / Updates | `WorkflowSignalReceived`, `WorkflowUpdateHandled`, `waitSignal` / `waitUpdate`, `DeliverWorkflowSignalMessage` / `DeliverWorkflowUpdateMessage` + handlers, `WorkflowQueryEvaluator` / `WorkflowQueryRunner` | **Partial** (queries = log read; signal/update transport = Messenger + append + `dispatchResume`) |

Consequences
---

- **Replay activity with `null` result**: use `array_key_exists` (not `isset`) to detect `ActivityCompleted` in the replay result map; otherwise an activity whose result is `null` is treated as incomplete (risk of re-scheduling / suspension loop).
- Every new persistent event type must be registered in `EventSerializer` (Dbal serialization / replay).
- Distributed workflow tests that compare logs event-by-event must extend constraints (e.g. `DistributedWorkflowJournalEquivalentConstraint`) when new types appear in expected scenarios.
- [PRD001](../prd/PRD001-current-component-state.md) keeps the “Temporal ↔ Durable” matrix updated for product teams.

References
---

- [OST004 — Temporal parity](../ost/OST004-workflow-temporal-feature-parity.md)
- [ADR007 — Workflow recovery](ADR007-workflow-recovery.md)
- [ADR009 — Distributed workflow dispatch](ADR009-distributed-workflow-dispatch.md)
- [PRD001 — Current state](../prd/PRD001-current-component-state.md)
