# DUR005 — Implementation backends: Temporal and In-Memory

## Status

Accepted

## Context

The Durable component must integrate with a real orchestrator for production while remaining **testable** and usable in **development** without heavy dependencies. Project constraints exclude certain runtimes (see DUR006).

## Decision

For the **short and medium term**, only two implementation backends are **in scope**:

1. **Temporal** — real orchestration, persisted history, workers compliant with the protocol expected by the component (no official PHP SDK per DUR006).
2. **In-Memory** — local simulation of the same **ports** (EventStore, Command/Query repositories, replay / stub behaviour) for tests and prototypes.

### Principles

- Both backends expose the **same abstractions** (ports) documented in DUR001–DUR004.
- No third “official” backend (other workflow engine, other broker) is targeted in this scope; adding one would require **new ADRs**.

### Roles

- **Temporal**: operational truth, scalability, observability from the Temporal stack. The **bridge** (**[DUR019](DUR019-temporal-grpc-bridge-and-journal.md)**) and **native interpreter + activities** (**[DUR024](DUR024-temporal-native-execution-and-interpreter.md)**) follow **spike-first** orchestration (**[DUR026](DUR026-spike-first-temporal-orchestration.md)**).
- **In-Memory**: fast feedback, controlled determinism in tests, no network.

### Symfony Messenger

- **Symfony Messenger** integration for workflow resumes, activities, and related queues in Symfony contexts is specified in **DUR021**.

## Consequences

- Changes that only Temporal can support must stay behind interfaces so In-Memory is not broken.
- Documentation and examples may assume either backend without implying a third.

## Relationship to workflow and activity authoring (DUR022, DUR023)

- **Temporal** and **In-Memory** both enforce the **same** user-facing **authoring** model: workflow **constructor** only **`WorkflowEnvironment`**; activities with **DI** on the worker; **`ActivityInvoker`** from the environment (**DUR022**, **DUR023**).
- In-Memory **simulates** workers and orchestration without relaxing workflow constructor rules (no “test-only” injection of services into the workflow class).

## Synchronous completion in multi-process setups (Temporal native backend)

When the HTTP process and worker processes are **separate** (the standard production topology), `DurableSampleWorkflowRunner` must not assume that the worker runs in the same PHP process.

### `WorkflowClient.pollForCompletion()`

`DurableSampleWorkflowRunner.waitForWorkflowCompletion()` delegates to `WorkflowClient.pollForCompletion()` when `WorkflowClient` is injected (Temporal native). This method:

1. Computes `workflowId = 'durable-{executionId}'`.
2. Calls `TemporalHistoryCursor.closeEvent()` — a lightweight `GetWorkflowExecutionHistory` with `HISTORY_EVENT_FILTER_TYPE_CLOSE_EVENT` that fetches **only the terminal event** (completed, failed, timed out, etc.).
3. If no close event found: sleeps `refreshIntervalMs` ms and retries, up to `maxRefreshes` attempts.
4. On success: decodes and returns the workflow result payload.
5. On failure / timeout: throws a descriptive `RuntimeException`.

Default parameters: 500 ms interval, 120 retries = **60 seconds total maximum wait**.

This avoids:
- Relying on `InMemoryEventStore` (process-local, invisible to other processes).
- Driving the Temporal worker inside the HTTP request (not needed when a worker process exists).
- Long-poll blocks on `durable_temporal_activity` after the workflow completes.

### `NullEventStore` (internal `ExecutionRuntime`)

`WorkflowTaskRunner` creates its own `ExecutionRuntime` with a `NullEventStore`. This is **correct and intentional**: for the Temporal backend, Temporal is the persistent event store for workflow history. The Durable `EventStoreInterface` is not used for workflow-level events; history is fetched from Temporal via `TemporalHistoryCursor` on every task poll.

### Activity-side events

`ActivityMessageProcessor` writes `ActivityCompleted` to the shared `InMemoryEventStore`. `TemporalActivityWorker` reads from this store to confirm the activity outcome before sending `RespondActivityTaskCompleted` to Temporal. This in-process handshake is **scoped to the worker process only** and does not affect the HTTP process.

### `DurableMessengerDrain` (in-memory backend only)

`DurableMessengerDrain` is the completion-detection strategy for the **in-memory backend**. When `WorkflowClient` is **not** injected (no Temporal DSN configured), `DurableSampleWorkflowRunner` falls back to the drain. The drain's `pollTemporalMirrorTransportsIfRegistered()` accepts `$eventStore` and `$executionId` to check for completion before each transport poll, preventing unnecessary blocking.
