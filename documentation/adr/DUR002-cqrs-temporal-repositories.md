# DUR002 — WorkflowClient, WorkflowHistorySourceInterface, WorkflowCommandBufferInterface

## Status

Accepted

## Context

The Durable component interacts with Temporal through three distinct concerns:

1. **Client-side control** — starting, signalling, querying, and updating workflows from application code
2. **Reading history for replay** — a worker must read Temporal history events to replay the workflow fiber
3. **Writing commands** — a worker must collect orchestration commands and deliver them to Temporal

These three concerns are separated as explicit ports (hexagonal architecture: DUR004, DUR012) with distinct Temporal backend adapters and in-memory backend adapters (DUR005).

## Decision

### WorkflowClient — the client-side port

`WorkflowClient` is the application-facing API for driving workflow executions from outside the worker:

| Method | Temporal RPC | Description |
|---|---|---|
| `startAsync(string $workflowType, array $payload, string $executionId): string` | `StartWorkflowExecution` | Fire and forget; returns the run ID |
| `startSync(string $workflowType, array $payload, string $executionId): mixed` | `StartWorkflowExecution` + long-poll `GetWorkflowExecutionHistory` | Blocks until `WorkflowExecutionCompleted` |
| `signal(string $executionId, string $signalName, array $args): void` | `SignalWorkflowExecution` | Delivers an external signal |
| `query(string $executionId, string $queryType, array $args): mixed` | `QueryWorkflow` | Read-only query via `WorkflowServiceExecutionRpc` |
| `update(string $executionId, string $updateName, array $args): mixed` | `UpdateWorkflowExecution` + `PollWorkflowExecutionUpdate` | Transactional update via `WorkflowServiceExecutionRpc` |

The concrete Temporal implementation is `TemporalWorkflowClient`. For the in-memory backend, an `InMemoryWorkflowClient` implementation drives `ExecutionEngine` directly.

### WorkflowHistorySourceInterface — the replay read port

`WorkflowHistorySourceInterface` is injected into `ExecutionContext` to provide read-only access to the recorded history for slot-based replay:

```php
interface WorkflowHistorySourceInterface {
    public function findActivitySlotResult(int $slot): ?array;
    public function isActivitySlotFailed(int $slot): bool;
    public function findTimerSlotCompleted(int $slot): bool;
    public function findSignalForSlot(string $signalName, int $slot): ?array;
    public function findSideEffectForSlot(int $slot): mixed;
    public function findChildWorkflowForSlot(int $slot): ?array;
}
```

| Backend | Implementation |
|---|---|
| Temporal | `TemporalExecutionHistory` built from `TemporalHistoryCursor` events |
| In-memory | `EventStoreHistorySource` wrapping `EventStoreInterface` |

### WorkflowCommandBufferInterface — the write port

`WorkflowCommandBufferInterface` is injected into `ExecutionContext` as the target for new orchestration commands discovered during replay:

```php
interface WorkflowCommandBufferInterface {
    public function scheduleActivity(string $activityId, string $activityName, array $payload, array $metadata): void;
    public function startTimer(string $timerId, float $scheduledAt, string $summary): void;
    public function completeWorkflow(mixed $result): void;
    public function failWorkflow(\Throwable $reason): void;
    public function cancelActivity(string $activityId): void;
    /** @return list<Command> */
    public function getCommands(): array;
}
```

| Backend | Implementation |
|---|---|
| Temporal | `TemporalWorkflowCommandBuffer` — builds `Command` protobuf objects for `RespondWorkflowTaskCompleted` |
| In-memory | `EventStoreCommandBuffer` — appends domain events to `EventStoreInterface` |

### Principles

- All three are **ports**: Temporal and in-memory adapters implement them differently (DUR005)
- Types returned to application code are Durable's own — no proprietary types from a forbidden SDK (DUR006)
- Error classification and retries follow DUR011; transport and mapping follow DUR012
- Testability: the in-memory backend validates logic without a Temporal cluster (DUR015)

## Consequences

- `WorkflowClient` replaces `TemporalWorkflowStarter`; the method `startWorkflowForExecution` is replaced by `startAsync`
- `WorkflowHistorySourceInterface` replaces `EventStoreInterface` as the replay read source in `ExecutionContext`; `EventStoreInterface` remains valid only for domain event persistence in the in-memory backend
- `WorkflowCommandBufferInterface` replaces the dual role of `EventStoreInterface` for writes in the Temporal execution path
- The former `TemporalStartingEventStore` decorator (which started Temporal workflows when `ExecutionStarted` was appended to the event store) is removed; workflow start is the responsibility of user application code calling `WorkflowClient.startAsync()`

## Relationship to other ADRs

- **DUR003** — fiber-based replay; `WorkflowHistorySourceInterface` is the read input for the replay loop
- **DUR005** — Temporal and in-memory backends; each provides its own implementations of the three ports
- **DUR012** — API client and repository adapter layers; these ports follow the hexagonal adapter pattern
- **DUR027** — `WorkflowTaskRunner` defines the concrete algorithm that consumes these ports
