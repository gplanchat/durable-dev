# Error handling and retries

ADR008-error-handling-retries
===

Introduction
---

This **Architecture Decision Record** defines error handling and retry strategy for activities and workflows in the Durable project. It covers persisting failures, restoration at `await()`, non-serializable (catastrophic) failures, and the contract that “workflows must handle activity errors”.

Three cases when an activity throws after retries are exhausted
---

1. **Recovered in workflow**: a `try / catch` around `await()` absorbs the exception; the log contains `ActivityFailed` (or `ActivityCatastrophicFailure` if non-serializable) but **not** `WorkflowExecutionFailed`.
2. **Serializable failure not caught**: the exception is represented in the event store (`ActivityFailed` + `FailureEnvelope`). On `await()` / replay, an exception is thrown again:
   - if it implements `DeclaredActivityFailureInterface`, **restore** via `restoreFromActivityFailureContext()`;
   - otherwise **`DurableActivityFailedException`** with `activityId`, `activityName`, `attempt`, trace, context, and reconstructed **`previous`** chain (`ActivityFailureCauseException`).
3. **Non-serializable failure**: JSON payload impossible (e.g. invalid declared context) → **`ActivityCatastrophicFailure`** in the log and **`DurableCatastrophicActivityFailureException`** at `await()`. Treat as a **severe** code / data failure.

Workflow and integration: unhandled failure
---

If an activity error (`DurableActivityFailedException`, `DeclaredActivityFailureInterface`, `DurableCatastrophicActivityFailureException`) **escapes the handler** without `try / catch`, `ExecutionEngine`:

- appends **`WorkflowExecutionFailed`** (appropriate kind);
- throws **`DurableWorkflowAlgorithmFailureException`** with `getPrevious()` = the activity exception.

This represents an **algorithm / integration failure**: the workflow should have anticipated the failure.

**Suspension**: `WorkflowSuspendedException` is **not** a failure: it propagates without `WorkflowExecutionFailed`.

Identifying the source
---

- Each **`ActivityScheduled`** ties a stable `activityId`; **`ActivityFailed`** / **`ActivityCatastrophicFailure`** repeat `activityId`, `activityName`, `failureAttempt` (attempt at failure time).
- **`FailureEnvelope`** includes the **trace** of the root exception and a serialized **`previousChain`** `{ class, message, code }[]` for `getPrevious()` causes.
- **`DurableActivityFailedException`** exposes `envelope()` for logs / traces without re-reading the store.

Error classification (reminder)
---

### Business errors (non-retryable)

- **NotFoundException**: missing resource
- **ValidationException**: invalid data
- **BusinessLogicException**: rule violation
- **DuplicateResourceException**: conflict (e.g. already created)

These **must not** be retried: another attempt would fail the same way. For deterministic propagation, prefer **`DeclaredActivityFailureInterface`**.

### System errors (retryable)

- Network timeouts
- Temporary unavailability (503, connection refused)
- Deadlocks
- OutOfMemory (with worker restart)

Retries
---

- **Configuration**: `max_retries` at worker level (`ActivityMessageProcessor` / `ActivityRunHandler`, `ExecutionRuntime`)
- **Behavior**: if `message->attempt() <= maxRetries`, the message is re-enqueued with `withAttempt(attempt + 1)`
- **Exhaustion**: `ActivityFailureEventFactory::fromActivityThrowable()` produces `ActivityFailed` or `ActivityCatastrophicFailure`

Exponential backoff (possible extension)
---

A future change could add delay between retries (exponential backoff):
- Delay = `initialDelay * (multiplier ^ attempt)`
- Configurable per activity or globally

Logging
---

- Log with: `executionId`, `activityId`, `activityName`, `attempt`, `failureClass`, message excerpt (no sensitive data)
- Business exceptions must not expose sensitive data in messages or in `toActivityFailureContext()`

References
---

- [ADR018 - No silent catch blocks](ADR018-no-silent-catch-blocks.md) — règles PHP applicables à tout `catch` (pas de mise sous silence sans log / traitement)
- [RUNTIME-RFC004 - Error Handling](../../architecture/runtime/rfcs/RUNTIME-RFC004-error-handling-logging.md)
- [ADR006 - Activity patterns](ADR006-activity-patterns.md)
- [src/Durable/Port/DeclaredActivityFailureInterface.php](../../src/Durable/Port/DeclaredActivityFailureInterface.php)
- [src/Durable/Failure/FailureEnvelope.php](../../src/Durable/Failure/FailureEnvelope.php)
- [src/Durable/Failure/ActivityFailureEventFactory.php](../../src/Durable/Failure/ActivityFailureEventFactory.php)
- [src/Durable/Event/ActivityFailed.php](../../src/Durable/Event/ActivityFailed.php)
- [src/Durable/Event/ActivityCatastrophicFailure.php](../../src/Durable/Event/ActivityCatastrophicFailure.php)
- [src/Durable/Event/WorkflowExecutionFailed.php](../../src/Durable/Event/WorkflowExecutionFailed.php)
- [src/Durable/Exception/DurableActivityFailedException.php](../../src/Durable/Exception/DurableActivityFailedException.php)
- [src/Durable/Exception/DurableWorkflowAlgorithmFailureException.php](../../src/Durable/Exception/DurableWorkflowAlgorithmFailureException.php)
