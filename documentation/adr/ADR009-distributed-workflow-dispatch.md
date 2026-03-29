# Distributed model and workflow re-dispatch

ADR009-distributed-workflow-dispatch
===

Introduction
---

This **Architecture Decision Record** defines the distributed execution model for Durable workflows, where workflows and activities run in separate processes. It complements [ADR007 - Recovery and replay](ADR007-workflow-recovery.md) by detailing re-dispatch implementation.

Context
---

In **inline** mode, workflows and activities run in the same process via **`drainActivityQueueOnce`**. For horizontal scalability, **distributed** mode (`durable.distributed: true`) allows:

- Activities to run on dedicated workers (`durable:activity:consume`)
- The workflow to “exit” after scheduling an activity or timer (suspension)
- The workflow to be **re-dispatched** when an activity completes, a timer fires, or a signal/update is delivered

Application workflows are **`#[Workflow]`** classes: the handler invoked by the engine is **`callable(WorkflowEnvironment $env): mixed`** (from **`WorkflowRegistry::getHandler($type, $payload)`** after **`registerClass`** or Symfony `durable.workflow` tag).

Re-dispatch principle
---

1. **Start**: A **`WorkflowRunMessage`** is dispatched with `executionId`, `workflowType`, `payload`
2. **Run**: **`WorkflowRunHandler`** loads metadata, gets the handler from the registry, calls **`ExecutionEngine::start`** or **`resume`**. On the first **`await`** for an activity or timer not yet resolved in distributed mode, the runtime throws **`WorkflowSuspendedException`** and the handler **returns** after requesting resume (depending on wait type).
3. **Activity**: A worker consumes the activity message, runs it, appends **`ActivityCompleted`** to the EventStore, then **`dispatchResume($executionId)`**
4. **Timers**: a process (cron, sync handler, etc.) dispatches **`FireWorkflowTimersMessage`**; the handler calls **`checkTimers`** and **`dispatchResume`** if timers have become due
5. **Resume**: A new **`WorkflowRunMessage`** (resume) is processed; the engine **replays** from the EventStore via **`ExecutionContext`** slots exposed to **`WorkflowEnvironment`**

### Continue-as-new

If the workflow calls **`WorkflowEnvironment::continueAsNew($workflowType, $payload)`** (delegating to context), the engine appends **`WorkflowContinuedAsNew`** on the current run (without **`ExecutionCompleted`**) and throws **`ContinueAsNewRequested`**. **`WorkflowRunHandler`** removes metadata for the old `executionId`, registers it for a **new** id, and dispatches a **`WorkflowRunMessage`** start (`dispatchNewWorkflowRun`) with the next run’s type and payload.

Prerequisites
---

- **`WorkflowRegistry`**: registration by **`#[Workflow]`** class (`registerClass`) or Symfony compilation
- **`WorkflowMetadataStore`**: persists `(executionId, workflowType, payload)` at start for resume across messages
- **`WorkflowResumeDispatcher`**: injected into the activity worker and control handlers (timers, signals, updates)

Transports
---

- **Activities**: configured transport (e.g. `durable_activities`) — **`ActivityMessage`**
- **Workflows**: dedicated transport (e.g. `durable_workflows`) — **`WorkflowRunMessage`**
- **Timers (wake)**: **`FireWorkflowTimersMessage`** — route (e.g. sync / cron) to **`FireWorkflowTimersHandler`**; append **`TimerCompleted`** + `dispatchResume` if at least one timer is due
- **Resume**: **`MessengerWorkflowResumeDispatcher`** uses **`DispatchAfterCurrentBusStamp`** to avoid stacking **`WorkflowRunMessage`** in synchronous recursion during the current handler

Configuration
---

```yaml
durable:
    distributed: true
```

The Messenger transport name for workflows is defined in **`framework.messenger.routing`** (see sample app `symfony/config/packages/messenger.yaml`). The bundle’s **`workflow_transport`** key, if present, documents the naming convention; actual wiring stays under **`framework`**.

When **`distributed: false`** (default), behavior remains inline (no **`WorkflowRunHandler`** on Messenger for the run body).

`any()` / `race()` concurrency
---

When a race of activities ends (first **`Awaitable`** resolved or rejected), activities **still queued** and **not consumed** are **removed from the transport** (best effort): **`ActivityTransportInterface::removePendingFor()`**. An **`ActivityCancelled`** event is appended with reason `race_superseded`, and the matching slot replays an **`ActivitySupersededException`**.

- **In-memory / DBAL**: message actually removed from pending.
- **Messenger**: no reliable removal without a dedicated API — returns `false`, activity may still run (acceptable).
- If the worker has **already drained the queue** before workflow resume, stray activities may already be **`ActivityCompleted`**: no cancellation then; history stays consistent.

Signal / update suspension vs activity / timer
---

**`WorkflowSuspendedException`** carries **`shouldDispatchResume()`**: in distributed mode, an **activity** or **timer** wait (`ActivityAwaitable`, `TimerAwaitable`, including inside `any()` / `CancellingAnyAwaitable`) triggers **`dispatchResume`** from **`WorkflowRunHandler`** (activity worker or timer wake can progress the run). A **signal** or **update** wait does **not** trigger that automatic re-dispatch: otherwise, with a **sync** Messenger transport, recursive resume would loop forever. Resume is then handled by **`DeliverWorkflowSignalMessage`** / **`DeliverWorkflowUpdateMessage`** (append log + **`dispatchResume`**).

Known limitations
---

- **Timers** in distributed mode require a process to dispatch **`FireWorkflowTimersMessage`** (or equivalent) so **`checkTimers`** can append **`TimerCompleted`** before a useful run resume
- **Workflow type** and **payload** must be reproducible: the registry resolves the handler from the string type and payload stored in metadata

References
---

- [ADR007 - Recovery and replay](ADR007-workflow-recovery.md)
- [ADR005 - Messenger integration](ADR005-messenger-integration.md)
- [PRD001 - Current state](../prd/PRD001-current-component-state.md)
