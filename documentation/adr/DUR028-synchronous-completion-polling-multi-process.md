# DUR028 — Synchronous workflow completion polling for multi-process Temporal setups

## Status

Accepted

## Context

In development and production environments, the **HTTP process** that starts a workflow and the **worker process** that executes workflow and activity tasks run in **separate PHP processes**. They do not share memory. Any approach that relies on an `InMemoryEventStore` updated by the worker to signal completion to the HTTP process is **incorrect by design**: each process has its own instance.

The `DurableMessengerDrain` (used by the in-memory backend) processes workflow and activity tasks inline in the same PHP process. This is correct for the **in-memory backend** (unit / integration tests, dev without a Temporal server), but not applicable to a real Temporal deployment.

## Decision

### `TemporalHistoryCursor::closeEvent()`

A lightweight gRPC call using `GetWorkflowExecutionHistory` with `HISTORY_EVENT_FILTER_TYPE_CLOSE_EVENT` is used to check whether a workflow has reached a terminal state. It fetches **at most one event** (the close event) rather than traversing the full history.

Return contract:
- Returns the close `HistoryEvent` (type `COMPLETED`, `FAILED`, `TIMED_OUT`, `CANCELED`, or `TERMINATED`) when the workflow has ended.
- Returns `null` when the workflow is still running **or** does not exist yet (`NOT_FOUND` / gRPC code 5).
- Throws `RuntimeException` for any other gRPC error code.

### `WorkflowClient::pollForCompletion()`

```php
public function pollForCompletion(
    string $executionId,
    int $refreshIntervalMs = 500,
    int $maxRefreshes = 120,
): mixed
```

Polls `closeEvent()` in a loop:

1. Compute `workflowId = 'durable-{executionId}'`.
2. Call `TemporalHistoryCursor::closeEvent(workflowId)`.
3. If `null` → sleep `refreshIntervalMs` ms and retry.
4. If `EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED` → decode and return the result payload.
5. If `EVENT_TYPE_WORKFLOW_EXECUTION_FAILED` → throw `RuntimeException` with failure message.
6. If `EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT` → throw `RuntimeException`.
7. Any other terminal event → throw `RuntimeException`.
8. After `maxRefreshes` exhausted with no terminal event → throw `RuntimeException`.

Default parameters give a **maximum wait of 60 seconds** (120 × 500 ms) before declaring a timeout.

### `DurableSampleWorkflowRunner::waitForWorkflowCompletion()`

The routing logic is:

```
if ($this->workflowClient !== null) {
    return $this->workflowClient->pollForCompletion($executionId);
}
// Fall back to DurableMessengerDrain for in-memory backend.
```

`WorkflowClient` is injected **optionally** via the Symfony DI container (`'@?Gplanchat\Bridge\Temporal\WorkflowClient'`). It is present when `DURABLE_DSN` is configured (Temporal native), absent otherwise (in-memory).

## Consequences

- The HTTP process polls Temporal directly, independently of worker processes. No shared mutable state between processes.
- The `NullEventStore` in `WorkflowTaskRunner::ExecutionRuntime` remains correct: the worker does not need to write to the Durable `EventStoreInterface` for the HTTP process to detect completion.
- The polling interval and maximum wait are configurable at call-site, making them adaptable to workflow duration profiles.
- For very long workflows, the caller must either increase `maxRefreshes` or use an async callback / webhook pattern (out of scope for this ADR).
- `DurableMessengerDrain` remains unchanged and continues to work for the in-memory backend.

## Relationship to other ADRs

- **DUR005** — In-memory vs Temporal backends: updated to describe this polling pattern.
- **DUR024** — `WorkflowTaskRunner` fiber-based interpreter: the runner uses `NullEventStore` internally; it does not need to notify the HTTP process.
- **DUR027** — Workflow task runner and fiber replay: unaffected.
- **DUR025** — Temporal gRPC RPCs: `GetWorkflowExecutionHistory` with `HISTORY_EVENT_FILTER_TYPE_CLOSE_EVENT` is the specific RPC used here.
