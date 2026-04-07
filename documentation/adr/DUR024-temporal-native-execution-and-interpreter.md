# DUR024 — Temporal native execution: WorkflowTaskRunner and fiber-based interpreter

## Status

Accepted

## Context

[DUR019](DUR019-temporal-grpc-bridge-and-journal.md) describes the gRPC bridge. [DUR026](DUR026-spike-first-temporal-orchestration.md) establishes that all orchestration commands flow through `RespondWorkflowTaskCompleted` only. [DUR027](DUR027-workflow-task-runner-fiber-replay.md) defines `WorkflowTaskRunner` as the central workflow worker component.

This ADR defines how the **fiber-based interpreter** replaces the former `JournalActivityInterpreter` naive state machine. It also defines the mapping between Durable concepts and Temporal artifacts visible in the Web UI.

This ADR does not use the official Temporal PHP SDK or RoadRunner (DUR006).

## Decision

### 1. WorkflowTaskRunner as the interpreter

`WorkflowTaskRunner` is the interpreter that decides which commands to send to Temporal:

- It **runs the actual user workflow code** inside a `\Fiber` (via `ExecutionEngine`)
- It uses `TemporalHistoryCursor` to read the full execution history from Temporal
- It builds `TemporalExecutionHistory` as the `WorkflowHistorySourceInterface` input for replay
- It collects commands via `TemporalWorkflowCommandBuffer` (`WorkflowCommandBufferInterface`)
- The commands produced by real workflow code running against real history are **always correct** for any workflow pattern

This replaces `JournalActivityInterpreter` (and `TemporalActivityCommandBuilder`, `TemporalActivityHistoryIndex`) which were a naive state table that could only handle simple linear workflows and did not execute user code.

### 2. Reference: NativeExecutionSpike

`NativeExecutionSpike` (`NativeExecutionSpike::run`) remains the **minimal end-to-end behavioral reference**: StartWorkflow → schedule activity → complete activity → complete workflow, using gRPC only (DUR025 §2–§5).

It is preserved as documentation of the protocol. It is **not** part of the production code path.

### 3. Activity workers

Activity tasks are handled by `TemporalActivityWorker`:
- `PollActivityTaskQueue` → execute the activity implementation → `RespondActivityTaskCompleted` / `RespondActivityTaskFailed` / `RespondActivityTaskCanceled`
- Activity type names map from Durable contracts (DUR004, DUR023)

### 4. Source of truth

- **Temporal workflow history** is authoritative for orchestration state visible in the Web UI
- Commands come from `WorkflowTaskRunner` running user code — not from a bootstrap payload or a secondary journal
- The `TemporalNativeBootstrap` payload mechanism (`temporalNativeSchedule`, `temporalNativeComplete`) is removed

### 5. Symfony Messenger

`ResumeWorkflowHandler` (Messenger handler) is used only for the **in-memory backend** to advance workflow state after activity completion. For the Temporal backend, progression is driven entirely by the `WorkflowTaskProcessor` poll loop. See DUR027 §9.

## Mapping (Durable ↔ Temporal)

| Durable concept | Temporal artifact |
|---|---|
| `#[Workflow]` type string | `WorkflowType.name` on `StartWorkflowExecution` |
| `#[ActivityMethod]` / contract | `ActivityType.name` on `ScheduleActivityTask` command |
| `await activity(...)` — new slot | `COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK` → `ACTIVITY_TASK_SCHEDULED` |
| `await delay(...)` — new slot | `COMMAND_TYPE_START_TIMER` → `TIMER_STARTED` |
| `#[WorkflowMethod]` return | `COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION` |
| Unhandled exception | `COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION` |
| `waitSignal()` | Resolved from `WORKFLOW_EXECUTION_SIGNALED` history event |
| `#[QueryMethod]` | Answered in `RespondWorkflowTaskCompleted::query_results` |
| `#[UpdateMethod]` | Resolved from `WORKFLOW_EXECUTION_UPDATE_ACCEPTED` history event |

## Consequences

- Any workflow expressible in PHP is correctly handled: the interpreter is the user's own workflow code running in a fiber, not a limited state table
- Integration tests assert real Temporal history events (`ActivityTaskScheduled`, `ActivityTaskCompleted`, `WorkflowExecutionCompleted`) produced by `WorkflowTaskRunner` (DUR010)
- Observability: Temporal Web UI is primary for orchestration timelines (DUR017)

## Relationship to other ADRs

- **DUR005** — Temporal vs in-memory backends; `WorkflowHistorySourceInterface` is the switching point
- **DUR013** — Query / Signal / Update; DUR024 maps them to history events and fiber slots
- **DUR025** — RPC catalog; all commands flow through the paths listed there
- **DUR027** — full `WorkflowTaskRunner` algorithm
