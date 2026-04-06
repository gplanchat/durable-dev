# DUR029 — Temporal read-through event store and profiler event conversion

## Status

Accepted

## Context

The Symfony **profiler** (`DurableDataCollector`) displays the workflow history for each HTTP
request using the `EventStoreInterface`. In a **multi-process Temporal setup**, the HTTP process
that handles the request is separate from the worker processes that execute workflow and activity
tasks. As a result, the in-process `InMemoryEventStore` available to the HTTP process is **empty**:
no events are written there by the worker.

`DurableDataCollector` therefore showed a blank panel for every workflow started through Temporal,
even when the workflow completed successfully and was fully visible in the Temporal UI.

A symmetric problem exists for reading Temporal workflow history in PHP: Temporal history events
are `HistoryEvent` protobuf messages, whose types do not map one-to-one to the Durable `Event`
domain objects. A translation layer is required.

## Decision

### `TemporalReadThroughEventStore`

A new `EventStoreInterface` implementation (`Bridge\Temporal\Store\TemporalReadThroughEventStore`)
acts as a **transparent read-through adapter** in front of the existing in-process store.

**Write path** — `append()` always delegates to the local `InMemoryEventStore`. This preserves
the in-process activity handshake between `ActivityMessageProcessor` and the temporal activity
worker (which must see `ActivityScheduled` / `ActivityCompleted` events to correlate tasks).

**Read path** — `readStream()`, `readStreamWithRecordedAt()`, `countEventsInStream()` check the
local store first. If the local store is empty for the requested execution, they fall back to
fetching the full workflow history from Temporal via `TemporalHistoryCursor` and converting each
`HistoryEvent` with `TemporalEventConverter`.

```
HTTP process reads profiler data
        │
        ▼
TemporalReadThroughEventStore
        │
        ├─ localStore has events? ──► YES ─► return local events
        │
        └─ NO ──► TemporalHistoryCursor.fullHistory(executionId)
                        │
                        ▼
                  TemporalEventConverter.convert(HistoryEvent) → Event|null
                        │
                        ▼
                  yield Durable Event stream
```

### `TemporalEventConverter`

A **stateful** converter (`Bridge\Temporal\Profiler\TemporalEventConverter`) translates a single
execution's Temporal `HistoryEvent` stream to the equivalent Durable `Event` objects.

It is stateful because Temporal's history represents cross-event relationships through integer
IDs:
- `ACTIVITY_TASK_COMPLETED` carries the `scheduledEventId` that refers back to the
  `ACTIVITY_TASK_SCHEDULED` event, which contains the actual activity name. The converter
  maintains a `scheduledEventId → activityId` map to reconstruct the relationship.
- `TIMER_FIRED` carries the `startedEventId` that refers back to `TIMER_STARTED`.
  The converter maintains a `startedEventId → timerId` map.
- `MARKER_RECORDED` events of kind `SideEffect` are counted with an internal slot index.

One instance must be created **per execution stream** and must not be reused across executions.

Mapping:

| Temporal event type                              | Durable event                     |
|--------------------------------------------------|-----------------------------------|
| `WORKFLOW_EXECUTION_STARTED`                     | `ExecutionStarted`                |
| `ACTIVITY_TASK_SCHEDULED`                        | `ActivityScheduled`               |
| `ACTIVITY_TASK_COMPLETED`                        | `ActivityCompleted`               |
| `ACTIVITY_TASK_FAILED`                           | `ActivityFailed`                  |
| `ACTIVITY_TASK_CANCELED`                         | `ActivityCancelled`               |
| `TIMER_STARTED`                                  | `TimerScheduled`                  |
| `TIMER_FIRED`                                    | `TimerCompleted`                  |
| `MARKER_RECORDED` (kind=SideEffect)              | `SideEffectRecorded`              |
| `WORKFLOW_EXECUTION_COMPLETED`                   | `ExecutionCompleted`              |
| `WORKFLOW_EXECUTION_FAILED`                      | `WorkflowExecutionFailed`         |
| `WORKFLOW_EXECUTION_CANCELED`                    | `WorkflowCancellationRequested`   |
| `WORKFLOW_EXECUTION_SIGNALED`                    | `WorkflowSignalReceived`          |
| `START_CHILD_WORKFLOW_EXECUTION_INITIATED`       | `ChildWorkflowScheduled`          |
| `CHILD_WORKFLOW_EXECUTION_COMPLETED`             | `ChildWorkflowCompleted`          |
| All other types                                  | `null` (skipped)                  |

### `TemporalWorkflowResumeDispatcher` notifies `DurableExecutionTrace`

The HTTP-process dispatcher (`TemporalWorkflowResumeDispatcher`) calls
`DurableExecutionTrace::onWorkflowDispatchRequested()` after `WorkflowClient::startAsync()`.
This records the dispatch on the profiler timeline even before any history exists in Temporal.

### Wiring in `DurableExtension`

When a Temporal DSN is configured:
1. `InMemoryEventStore` is registered as the **local** store.
2. `TemporalReadThroughEventStore` wraps it and is aliased as `EventStoreInterface`.
3. `TemporalWorkflowResumeDispatcher` receives `DurableExecutionTrace` via constructor injection.

## Consequences

**Benefits**
- The profiler panel displays real workflow history from Temporal for any execution started
  in the current HTTP process, without requiring a shared database or file store.
- The in-process activity handshake is unaffected (writes still go to the local store).
- `TemporalEventConverter` can be reused independently for any code that needs to map
  Temporal history to the Durable event model (e.g. future replay utilities).
- No new storage mechanism is introduced: Temporal remains the sole source of truth
  (see rule `no-temporal-sdk-roadrunner.mdc`).

**Limitations / Trade-offs**
- The profiler panel is populated with a **live gRPC call** to Temporal on each profiler load.
  This call is bounded (full history for a single execution) and acceptable for development
  use; it should not be enabled in production profiler dumps.
- If the HTTP process is recycled before the profiler is accessed, the local store is empty
  and the read-through fetches from Temporal. If Temporal is unavailable at profiler access
  time, the panel will be empty (no crash).
- `TemporalEventConverter` does not handle every Temporal event type (e.g. `WORKFLOW_EXECUTION_TIMED_OUT`,
  `WORKFLOW_EXECUTION_TERMINATED`). These can be added incrementally as needed.

## Alternatives considered

| Alternative                                       | Reason rejected                                   |
|---------------------------------------------------|---------------------------------------------------|
| Shared database (e.g. DBAL) for profiler events   | Violates constraint: Temporal = single source of truth |
| File-based profiler event log                     | Same violation; also fragile in containerised envs |
| Periodic worker → HTTP push (e.g. Redis pub/sub)  | Complexity unjustified for a development tool     |
| Expanding `InMemoryEventStore` across processes   | Impossible without shared memory or IPC           |

## Related decisions

- [DUR005](DUR005-implementation-backends-temporal-and-in-memory.md) — In-memory vs Temporal backends
- [DUR024](DUR024-temporal-native-execution-and-interpreter.md) — Temporal-native execution
- [DUR027](DUR027-workflow-task-runner-fiber-replay.md) — WorkflowTaskRunner and fiber replay
- [DUR028](DUR028-synchronous-completion-polling-multi-process.md) — Synchronous completion polling
