# Current state of the Durable component

PRD001-current-component-state
===

Introduction
---

This **Product Requirements Document** describes the state of the **Durable** component and bundle after the “class-based workflows” refactor (`#[Workflow]` / `#[WorkflowMethod]`, `WorkflowEnvironment`, `ActivityStub`, activity contracts) and distributed-mode extensions (Messenger, persisted parent↔child link, child failure projection on the parent log).

Objectives
---

Provide a PHP library and Symfony bundle for **durable execution**: deterministic orchestration (replay from a log), asynchronous activities, conceptual alignment with Temporal.io, **without** RoadRunner or the Temporal SDK dependency.

Functional specifications
---

### Workflows

- **Primary model**: classes annotated `#[Workflow('TypeName')]` with a `#[WorkflowMethod]` method; the engine injects **`WorkflowEnvironment`** (await, typed activities, child, timers, signals, etc.).
- **Low-level model**: a `callable(WorkflowEnvironment $env): mixed` can still be registered or used for test harnesses.
- **Activities**: via **`ActivityStub`** and interfaces marked `#[ActivityMethod]`; activity name resolution via **`activity_contracts`** (bundle) and **`ActivityContractResolver`**.
- **Timers**: `WorkflowEnvironment` / context — `delay()` / `timer()`; log `TimerScheduled` / `TimerCompleted`; in distributed mode, **`FireWorkflowTimersMessage`** + handler to advance timers and re-dispatch resume.
- **Side effects**: result in `SideEffectRecorded`, not re-executed on replay ([ADR010](../adr/ADR010-temporal-parity-events-and-replay.md)).
- **Child workflows**: `executeChildWorkflow` or **`childWorkflowStub()`**; `ChildWorkflowOptions` (`workflowId`, `parentClosePolicy`); parent log `ChildWorkflowScheduled` / `ChildWorkflowCompleted` / **`ChildWorkflowFailed`** (enriched fields: kind / class / child workflow failure context when projected from the child log); parent/child coordinator; `WorkflowCancellationRequested` on *request cancel* ([ADR010](../adr/ADR010-temporal-parity-events-and-replay.md), [ADR009](../adr/ADR009-distributed-workflow-dispatch.md)).
- **Async child via Messenger**: with `child_workflow.async_messenger: true`, **`ChildWorkflowRunner`** dispatches a **`WorkflowRunMessage`**; **`WorkflowRunHandler`** finalizes the parent (`ChildWorkflowCompleted` / `ChildWorkflowFailed`) and uses **`ChildWorkflowParentLinkStoreInterface`** (**in_memory** or **DBAL** multi-instance).
- **Continue-as-new**: `WorkflowContinuedAsNew` + `ContinueAsNewRequested`; Messenger handler chains a new `executionId` ([ADR009](../adr/ADR009-distributed-workflow-dispatch.md)).
- **Signals / updates**: `waitSignal` / `waitUpdate`; delivery via Messenger + handlers; `WorkflowSuspendedException::shouldDispatchResume()` handled to avoid sync loops; **queries**: `WorkflowQueryEvaluator` / `WorkflowQueryRunner`.
- **Parallelism**: `parallel()`, `all()`, `any()`, `race()` (functions or equivalents via the environment).
- **Replay**: **slot** order (activities, timers, children, signals, …) reconstructed from the log.
- **Parent exception**: when child failure is observed on the parent log, **`DurableChildWorkflowFailedException`** exposes on replay fields aligned with **`ChildWorkflowFailed`** (`workflowFailureKind`, `workflowFailureClass`, `workflowFailureContext`) when present in the log.

### Event Store

- `EventStoreInterface`: `append(Event)`, `readStream(executionId)`
- Events: full set including `ChildWorkflowFailed` (optional enriched payload), `WorkflowExecutionFailed`, etc.
- Implementations: **`DbalEventStore`**, **`InMemoryEventStore`**

### Activity transport

- **`DbalActivityTransport`**, **`InMemoryActivityTransport`** (library)
- **`MessengerActivityTransport`** (`Gplanchat\Durable\Bundle\Transport`) — bundle only; requires `symfony/messenger`

### Activities

- **`RegistryActivityExecutor`**: registration by name (often aligned with contract methods `#[ActivityMethod]`)

### Symfony bundle

- **`DurableBundle`**: engine, runtime, **`WorkflowRegistry`** + **`WorkflowDefinitionLoader`** (`durable.workflow` tag), activity contract resolution, parent/child coordinator, Messenger handlers (signals, updates, timers, **`WorkflowRunHandler`**), **`ChildWorkflowParentLinkStoreInterface`**, **`WorkflowQueryRunner`**, **`durable:activity:consume`** command
- Key parameters: `event_store`, `workflow_metadata`, `activity_transport`, `max_activity_retries`, **`child_workflow.async_messenger`**, **`child_workflow.parent_link_store`** (`type`: `in_memory` | `dbal`, `table_name`)
- Resume: **`MessengerWorkflowResumeDispatcher`** + **`DispatchAfterCurrentBusStamp`**

### Sample application `symfony/`

- Temporal-like sample workflows in **`App\Durable\Workflow\`**, `durable.workflow` tag
- Config: log + metadata + parent link in **DBAL** in `dev`; **`when@test`** may switch to in_memory for kernel tests
- **`durable:schema:init`** command: idempotent creation of Durable DBAL tables (log, metadata, parent–child link)

Acceptance criteria
---

- [x] Class-based workflows + `WorkflowEnvironment` + `ActivityStub` + `#[ActivityMethod]` contracts
- [x] Workflow with activities and replay (unit / integration tests)
- [x] Messenger, Dbal, InMemory transports
- [x] Timers + distributed wake (`FireWorkflowTimersMessage`)
- [x] Side effects persisted and replay-safe
- [x] Inline and async Messenger child workflows + parent log + persistable parent link (DBAL)
- [x] Rich child failure projection on parent log + replay via `DurableChildWorkflowFailedException`
- [x] Continue-as-new, signals / updates, log queries
- [x] Parent close policy + explicit child id
- [x] Activity retries (options on stubs / executor)

Implementation status
---

| Component | Status | Notes |
|-----------|--------|-------|
| ExecutionEngine | Implemented | `WorkflowEnvironment`, optional coordinator / resolvers |
| ExecutionContext | Implemented | Slots, replay, activities, timers, side effects, child, signals, updates |
| WorkflowEnvironment | Implemented | Await façade / stubs / child |
| ChildWorkflowRunner | Implemented | Inline (`InMemoryWorkflowRunner`) or async Messenger |
| ExecutionRuntime | Implemented | await, drain, `checkTimers` (injectable clock) |
| EventStoreInterface | Implemented | Dbal, InMemory |
| ChildWorkflowParentLinkStoreInterface | Implemented | InMemory, **Dbal** (`createSchema`) |
| DurableBundle | Implemented | DI, commands, extended config |
| Distributed mode (Messenger) | **Implemented** | `WorkflowRunMessage`, resume, activities via dedicated transport |
| Temporal driver | Not implemented | [OST001](../ost/OST001-future-opportunities.md) |

### Temporal ↔ Durable parity matrix ([OST004](../ost/OST004-workflow-temporal-feature-parity.md))

*For remaining gaps, see [OST004](../ost/OST004-workflow-temporal-feature-parity.md). Summary:*

| Temporal feature | Durable status | Notes |
|------------------|----------------|-------|
| Side effects | **Supported** | |
| Durable timers | **Supported** | + `FireWorkflowTimers` message in distributed |
| Child workflows | **Partial → advanced** | Classes + log + async + DBAL link; SDK / advanced stub parity still partial |
| Continue-as-new | **Partial** | New `executionId`; not the same logical “run” identity as Temporal |
| Signals / Queries / Updates | **Partial** | Log + Messenger; rich client ergonomics out of scope |

References
---

- [INDEX.md](../INDEX.md)
- [ADR004 - Ports and Adapters](../adr/ADR004-ports-and-adapters.md)
- [ADR005 - Messenger](../adr/ADR005-messenger-integration.md)
- [ADR009 - Distributed model](../adr/ADR009-distributed-workflow-dispatch.md)
- [ADR010 - Temporal parity, events and replay](../adr/ADR010-temporal-parity-events-and-replay.md)
- [OST001 - Future opportunities](../ost/OST001-future-opportunities.md)
- [OST004 - Workflow / Temporal feature parity](../ost/OST004-workflow-temporal-feature-parity.md)
- Root README — sample `symfony/config/packages/durable.yaml`
