# DUR019 — Temporal gRPC bridge

## Status

Accepted

## Context

The Durable component communicates with Temporal exclusively via **gRPC** and checked-in **protobuf stubs** (DUR006), without the official Temporal PHP SDK or RoadRunner. The bridge wires Symfony configuration, `WorkflowServiceClient`, and the workflow/activity workers. All orchestration commands flow through `RespondWorkflowTaskCompleted` (DUR026); history is read via cursor-based pagination (DUR001, DUR027).

## Decision

### Bridge responsibilities

1. **Connection** — `TemporalConnection` holds target, namespace, TLS, identity, and task queue names. `WorkflowServiceClientFactory` creates the gRPC client.

2. **Workflow client** — `WorkflowClient` (DUR002) sends `StartWorkflowExecution`, `SignalWorkflowExecution`, `QueryWorkflow`, `UpdateWorkflowExecution`, and `PollWorkflowExecutionUpdate` on behalf of application code. It replaces the former `TemporalWorkflowStarter`.

3. **Workflow worker** — `TemporalWorkflowTaskPoller` (renamed from `TemporalJournalGrpcPoller`) long-polls `PollWorkflowTaskQueue`. `WorkflowTaskProcessor` (refactored from `JournalWorkflowTaskProcessor`) drives `WorkflowTaskRunner` (DUR027) for each task and calls `RespondWorkflowTaskCompleted` with commands from `WorkflowCommandBuffer` and query results from `#[QueryMethod]` evaluations.

4. **History cursor** — `TemporalHistoryCursor` implements `TemporalHistoryCursorInterface` for lazy, page-by-page `GetWorkflowExecutionHistory` calls following `next_page_token`. It replaces `HistoryPageMerger` which loaded full history upfront.

5. **Activity worker** — `TemporalActivityWorker` polls `PollActivityTaskQueue`, executes activities, and calls `RespondActivityTaskCompleted`, `RespondActivityTaskFailed`, or `RespondActivityTaskCanceled`.

6. **Heartbeat** — `TemporalActivityHeartbeatSender` implements `ActivityHeartbeatSenderInterface` (DUR027 §6) by calling `RecordActivityTaskHeartbeat`. The `cancel_requested` response flag is exposed via `isCancellationRequested()`. There is no fork-based heartbeat; `pcntl_fork()` is forbidden (DUR027 §5).

### History as single source of truth

**Temporal workflow history** is authoritative for workflow execution state. The workflow task runner reads history events through `TemporalHistoryCursor` to build a `TemporalExecutionHistory` used as `WorkflowHistorySourceInterface` (DUR002). There is no secondary domain event journal stored as workflow signals (DUR026).

### Removed mechanisms

The following mechanisms present in earlier iterations are removed:

- `HistoryPageMerger` — replaced by `TemporalHistoryCursor`
- `JournalTemporalHistoryReader` — replaced by `WorkflowTaskRunner` reading directly from cursor
- `JournalActivityInterpreter` / `TemporalActivityCommandBuilder` / `TemporalActivityHistoryIndex` — replaced by `WorkflowTaskRunner` running actual workflow code in a fiber
- `TemporalNativeBootstrap` and the `temporalNativeSchedule` / `temporalNativeComplete` payload keys — the bootstrap payload mechanism is removed; commands come from running the real workflow code
- `TemporalStartingEventStore` — workflow start is the responsibility of user application code calling `WorkflowClient.startAsync()`
- `TemporalActivityHeartbeatFork` and `TemporalHeartbeatActivityCancellationChecker` — replaced by cooperative `ActivityHeartbeatSenderInterface`

### Deployment

One Temporal namespace for workflow and activity workers. Task queue names are configurable via `TemporalConnection`.

### Runtime requirements

PHP `grpc` extension is required. Protobuf stubs live in the bridge package. Standard PHP-CLI runtime; Swoole and OpenSwoole are not supported.

### NativeExecutionSpike

`NativeExecutionSpike` is kept as a **reference implementation** and behavioral test fixture. It is not part of the production code path. ADR references to it document the minimal end-to-end protocol.

## Consequences

- The bridge is an **adapter** (DUR012) behind gRPC; no SQL journal store
- Integration tests with a real Temporal server cover `StartWorkflowExecution`, `WorkflowTaskProcessor`, and activity worker paths
- Observability: Temporal Web UI is primary for orchestration timelines (DUR017)

## Relationship to other ADRs

- **DUR001** — cursor-based pagination; `TemporalHistoryCursor` is the Temporal implementation
- **DUR002** — `WorkflowClient`, `WorkflowHistorySourceInterface`, `WorkflowCommandBufferInterface`
- **DUR006** — gRPC only; no official SDK
- **DUR025** — RPC catalog
- **DUR026** — commands-only orchestration; no journal signals
- **DUR027** — `WorkflowTaskRunner` and fiber-based replay algorithm
