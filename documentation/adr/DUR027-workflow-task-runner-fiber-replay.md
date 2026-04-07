# DUR027 — WorkflowTaskRunner: fiber-based replay from Temporal history

## Status

Accepted

## Context

Prior implementations used two disconnected execution paths: `ExecutionEngine` ran user workflow code synchronously (throwing exceptions to simulate suspension), while `JournalActivityInterpreter` — a naive state table over journal rows — decided Temporal commands without ever running user code. This made complex workflow patterns (conditional logic, parallel activities, timers, signals) impossible to support correctly on the Temporal backend.

The correct model — aligned with how Temporal SDKs work — is:

> For each `PollWorkflowTaskQueue` response, run the user's workflow code from scratch inside a `\Fiber`, consuming history events as replay inputs, and collect the resulting commands for `RespondWorkflowTaskCompleted`.

This ADR defines the architecture for that model.

## Decision

### 1. WorkflowTaskRunner — the central workflow worker component

`WorkflowTaskRunner` is the single component responsible for:

1. Receiving a `PollWorkflowTaskQueueResponse`
2. Obtaining a `TemporalHistoryCursor` over the workflow's full history
3. Constructing an `ExecutionHistory` (ordered slot map from history events)
4. Looking up the workflow class via `WorkflowRegistry`
5. Creating a `WorkflowCommandBuffer` to collect new commands
6. Building `ExecutionContext` with `ExecutionHistory` as read source and `WorkflowCommandBuffer` as write target
7. Instantiating the workflow class with `WorkflowEnvironment` (DUR022)
8. Running the `#[WorkflowMethod]` inside a `\Fiber` via `ExecutionEngine`
9. **Replay loop**: for each fiber suspension point:
   - if a result already exists in `ExecutionHistory` for this slot → resolve the awaitable and resume the fiber
   - otherwise → record the new command in the buffer and stop
10. After fiber completion or the first unresolved suspension: returning the accumulated commands
11. Signal events (`WORKFLOW_EXECUTION_SIGNALED` in history) are injected as resolved slots for `waitSignal()` calls
12. Query requests from the poll response are evaluated after the fiber reaches its current state, by invoking `#[QueryMethod]` methods without advancing the fiber further

### 2. TemporalHistoryCursor — cursor-based history pagination

`TemporalHistoryCursor` implements the cursor-based pagination pattern from [DUR001](DUR001-event-store-and-cursor.md):

```
interface TemporalHistoryCursorInterface {
    /** @return \Generator<int, HistoryEvent> */
    public function events(WorkflowExecution $execution, ?string $pageToken = null): \Generator;
}
```

- Uses `GetWorkflowExecutionHistory` with `next_page_token` for lazy page-by-page fetching
- Never loads the full history into memory as a single object
- Replaces `HistoryPageMerger` (which merged all pages upfront)

### 3. WorkflowHistorySourceInterface and WorkflowCommandBufferInterface

`ExecutionContext` is refactored to inject two ports:

**`WorkflowHistorySourceInterface`** — read-only access to recorded history for slot replay:
- `findActivitySlotResult(int $slot): ?array` — result or failure for activity slot N
- `findTimerSlotCompleted(int $slot): bool`
- `findSignalForSlot(string $signalName, int $slot): ?array`
- `findSideEffectForSlot(int $slot): mixed`
- `findChildWorkflowForSlot(int $slot): ?array`

**`WorkflowCommandBufferInterface`** — write target for new orchestration commands:
- `scheduleActivity(string $activityId, string $activityName, array $payload, array $metadata): void`
- `startTimer(string $timerId, float $scheduledAt, string $summary): void`
- `completeWorkflow(mixed $result): void`
- `failWorkflow(\Throwable $reason): void`
- `cancelActivity(string $activityId): void`
- `getCommands(): list<Command>` — returns built Temporal `Command` objects

**Backend mapping:**

| Backend | `WorkflowHistorySourceInterface` | `WorkflowCommandBufferInterface` |
|---|---|---|
| Temporal | `TemporalExecutionHistory` (from `TemporalHistoryCursor`) | `TemporalWorkflowCommandBuffer` |
| In-memory | `EventStoreHistorySource` (wraps `EventStoreInterface`) | `EventStoreCommandBuffer` (appends domain events) |

### 4. ExecutionEngine — Fiber manager

`ExecutionEngine` manages the `\Fiber` lifecycle:

- Creates and starts the `\Fiber` wrapping the `#[WorkflowMethod]` call
- On fiber suspension: returns the awaitable that caused the suspension to `WorkflowTaskRunner`
- `WorkflowTaskRunner` decides whether to resolve (replay) or collect a command (new)
- On fiber completion: collects the `CompleteWorkflow` command
- On unhandled exception: collects the `FailWorkflow` command

`ExecutionEngine` is **shared** between Temporal and in-memory backends. Only the injected `WorkflowHistorySourceInterface` and `WorkflowCommandBufferInterface` differ.

### 5. Fiber lifecycle constraints

- **Fibers are non-persistent**: created and destroyed within a single `PollWorkflowTaskQueue → RespondWorkflowTaskCompleted` cycle. The workflow code is replayed from scratch on each poll.
- **PHP-CLI standard runtime only**: Swoole, OpenSwoole, and FrankenPHP coroutine mode are not supported as workflow worker runtimes. These runtimes implement their own coroutine systems that conflict with PHP's `\Fiber`.
- **`pcntl_fork()` is forbidden** in this component and bundle. The former `TemporalActivityHeartbeatFork` (fork-based heartbeat) is replaced by a cooperative `ActivityHeartbeatSenderInterface` (see §6).
- **Workflow code must not use `\Fiber` directly**: creating fibers, calling `\Fiber::suspend()`, or using `\Fiber::getCurrent()` in workflow code is forbidden, as a consequence of the no-I/O authoring rule (see §7).

### 6. ActivityHeartbeatSenderInterface — cooperative heartbeat

`pcntl_fork()` being forbidden, the automatic child-process heartbeat is replaced by a cooperative port injectable into activity implementations:

```
interface ActivityHeartbeatSenderInterface {
    public function send(array $details = []): void;
    public function isCancellationRequested(): bool;
}
```

- `TemporalActivityHeartbeatSender`: gRPC implementation calling `RecordActivityTaskHeartbeat`; the `cancel_requested` flag from the response is stored and returned by `isCancellationRequested()`
- `NullActivityHeartbeatSender`: no-op for in-memory backend and tests
- Activities call `$this->heartbeat->send([...])` at their own checkpoints; the worker injects the concrete implementation

### 7. Workflow authoring rule — no I/O (primary rule)

> Workflow code must perform **no I/O** (network, database, filesystem) and no non-deterministic operations (raw system clock, unlogged randomness). All I/O belongs in activities.

The prohibition of `\Fiber::suspend()`, manual `\Fiber` creation, and async components that internally use fibers (e.g. Symfony HTTP Client in async Revolt mode) follows as a **technical consequence** of this primary rule: those components perform I/O or interfere with the Durable fiber scheduler. Documentation should name the principle, not enumerate forbidden primitives.

### 8. WorkflowTaskProcessor — the poll/respond loop

`WorkflowTaskProcessor` (refactored from `JournalWorkflowTaskProcessor`) orchestrates:

1. Receives `PollWorkflowTaskQueueResponse` from `TemporalWorkflowTaskPoller`
2. Calls `WorkflowTaskRunner::run()` to get commands
3. Evaluates query results (from `#[QueryMethod]` invocations post-replay)
4. Sends `RespondWorkflowTaskCompleted` with commands + query results

### 9. ResumeWorkflowHandler — in-memory Messenger progression

`ResumeWorkflowHandler` (renamed from `WorkflowRunHandler`) handles `ResumeWorkflowMessage` (renamed from `WorkflowRunMessage`):

- **Used only for the in-memory backend**: advances the workflow via `ExecutionEngine.resume()` after each activity or timer completion via Symfony Messenger
- **Not used for the Temporal backend**: progression is driven entirely by the `WorkflowTaskProcessor` poll loop
- The former `!$message->isResume()` branch (starting workflows via Messenger) is **removed**: workflow start is the responsibility of user application code calling `WorkflowClient.startAsync()` (Temporal) or `ExecutionEngine.start()` directly (in-memory)

## Consequences

- `WorkflowTaskRunner` unifies the two previously disconnected execution paths (local engine + naive interpreter) into one fiber-based component
- Any workflow pattern expressible in PHP code (conditionals, loops, parallel activities, timers, signals, child workflows) is now correctly supported on the Temporal backend
- The `TemporalNativeBootstrap` payload mechanism (`temporalNativeSchedule`, `temporalNativeComplete`) is eliminated; decisions come from running the actual workflow code
- Integration tests assert Temporal history events (activity scheduled/completed, workflow completed) produced by the real workflow code execution

## Relationship to other ADRs

- **[DUR001](DUR001-event-store-and-cursor.md)** — cursor-based pagination; `TemporalHistoryCursor` is the Temporal implementation
- **[DUR002](DUR002-cqrs-temporal-repositories.md)** — `WorkflowClient` (client port), `WorkflowHistorySourceInterface` (read), `WorkflowCommandBufferInterface` (write)
- **[DUR003](DUR003-workflow-state-machine-replay-and-awaitables.md)** — fiber and replay model; this ADR defines the concrete algorithm
- **[DUR005](DUR005-implementation-backends-temporal-and-in-memory.md)** — Temporal and in-memory backends; `WorkflowHistorySourceInterface` is the backend-switching point
- **[DUR019](DUR019-temporal-grpc-bridge-and-journal.md)** — gRPC bridge wiring
- **[DUR022](DUR022-workflow-class-interface-and-workflow-environment.md)** — workflow authoring; `WorkflowEnvironment` is the only API surface for workflow code
- **[DUR025](DUR025-temporal-grpc-workflowservice-messages-and-implementation-map.md)** — RPC map; all commands flow through `WorkflowCommandBuffer → RespondWorkflowTaskCompleted`
