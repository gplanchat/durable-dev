# DUR026 — Commands-only orchestration path

## Status

Accepted

## Context

`NativeExecutionSpike` demonstrates the minimal end-to-end Temporal protocol: `StartWorkflowExecution` → `PollWorkflowTaskQueue` → `RespondWorkflowTaskCompleted` with `ScheduleActivityTask` → activity poll/respond → second workflow poll → `CompleteWorkflowExecution`. No workflow signals carry a parallel Durable domain journal.

The Durable runtime uses `EventStoreInterface` (DUR001) and domain events for in-memory backend replay (DUR003). A design that mirrors every domain event into Temporal via `SignalWorkflowExecution` would duplicate sources of truth and diverge from the spike.

## Decision

### Normative orchestration model

All orchestration commands in Temporal (`ScheduleActivityTask`, `StartTimer`, `CompleteWorkflowExecution`, `FailWorkflowExecution`, `RequestCancelActivityTask`, and future command types) are emitted **only** through `RespondWorkflowTaskCompleted` after `PollWorkflowTaskQueue`, following the structural pattern of `NativeExecutionSpike::run`.

**`SignalWorkflowExecution` is not used** to persist domain events or to decide the next orchestration command. User-facing workflow signals (DUR013) are a distinct concern; they are not the Durable–Temporal journal bridge.

### Source of commands

Commands are produced by `WorkflowTaskRunner` (DUR027) running the actual user workflow code in a fiber, consuming `TemporalHistoryCursor` history as the replay input (`WorkflowHistorySourceInterface`). There is no bootstrap payload — the workflow code itself decides all commands.

### Domain EventStore vs Temporal history

- `EventStoreInterface` (DUR001) is the port for **in-memory backend** domain event persistence
- **Temporal workflow history** is the authoritative sequence for **Temporal backend** orchestration and Web UI visibility
- There is no requirement to mirror `EventStore::append` calls into Temporal signals for orchestration purposes

### Reference implementation

`NativeExecutionSpike` is the behavioral reference for the gRPC-only Temporal protocol (DUR006). It is preserved as documentation; it is not part of the production code path.

## Consequences

- Integration tests assert real Temporal history events (`ActivityTaskScheduled`, `ActivityTaskCompleted`, `WorkflowExecutionCompleted`) when the Temporal backend is used (DUR010)
- Symfony Messenger (DUR021) is used for the in-memory backend only; it is orthogonal to the gRPC orchestration contract

## Relationship to other ADRs

- **DUR019** — gRPC bridge wiring; `WorkflowClient`, `TemporalWorkflowTaskPoller`
- **DUR024** — fiber-based interpreter; `WorkflowTaskRunner` produces all commands
- **DUR025** — RPC and message map
- **DUR027** — `WorkflowTaskRunner` algorithm; commands come from running real workflow code
