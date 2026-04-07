# DUR018 — Event parity, slots, and replay (Temporal alignment)

## Status

Accepted

## Context

The Durable engine relies on an **append-only** journal and **deterministic replay**. To align with Temporal capabilities (activities, timers, controlled side effects, children, continue-as-new, signals / queries / updates), each operation family must be represented by **stable events** and reproducible **slots** (see DUR001, DUR003).

## Decision

### Slots and order

- Each durable operation (activity, timer, side effect, child, signal, update, etc.) is associated with a **slot**: **sequential index per family** in the execution context. On replay, the engine finds the already recorded result for that slot instead of re-scheduling the effect.

### Side effects

- **Non-deterministic** values (outside activities) go through a dedicated primitive (**side effect** / equivalent on context): the **result** is **appended** to the journal; on replay the **closure is not re-run**, the value is read from the stream.

### Timers

- Scheduling / completion style events; replay relies on the timer sub-sequence in the journal.

### Activities — `null` result

- When resolving an activity **completed successfully** with a **`null`** result, “completion present” detection must use semantics like **`array_key_exists`** (or equivalent), **not** `isset` alone, to avoid confusing “not yet completed” with “completed with `null`”.

### Child workflows

- Typed surface such as **`childWorkflowStub`** / child execution with options (parent close policy, etc.); scheduling, completion, and child failure events; child failure is replayed in an enriched way from the journal when the model supports it.

### Continue-as-new

- End-of-run marker and chaining to a **new** history segment: a run only replays **its own** segment. **Possible gap** vs Temporal on identifiers (e.g. new `executionId` in Durable vs `WorkflowId` / `RunId` on the server): additional business correlation may live in **payload** or a dedicated field until the model evolves.

### Signals, queries, updates

- Accepted signals and updates are part of the **deterministic** journal; **queries** read state via replay / dedicated evaluator **without** producing durable commands from the handler (DUR013).

### Documented Temporal rule

- **Continue-as-new**: do not invoke from Signal/Update handlers without adequate synchronization — document for workflow authors (Temporal best practices alignment).

## Consequences

- Every new **persistent event type** must be registered in the journal serialization layer and covered by replay tests.
- Event-by-event journal comparison tests must be **extended** when new types appear.

## Relationship to workflow and activity authoring (DUR022, DUR023)

- **Replay** re-executes workflow code through **`WorkflowEnvironment`**: suspend points (**`await`**, **`ActivityInvoker`** calls) must line up with the same **slots** and history positions on replay (**DUR022**, **DUR003**).
