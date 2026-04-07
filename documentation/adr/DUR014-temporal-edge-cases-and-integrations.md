# DUR014 — Temporal edge cases and external integrations

## Status

Accepted

## Context

Distributed systems combine **asynchronous events**, **third-party APIs**, and **partial state**: **races** (one step reads data before another has produced it) are common without centralized orchestration. **Temporal** enforces **history order** and **replayable** workflow state, which mitigates a class of issues, but **integrations** (identity, queues, webhooks) still cause delays and transient inconsistencies.

## Decision

### Order and history

- The **workflow** is the source of truth for **ordering** steps visible in history: decisions are **serialized** by the execution model, not by unordered concurrent application event buses.

### Waiting for a business condition

When **external data** is not yet available (e.g. metadata created by another system), two strategy families **inside activities** are possible:

1. **Controlled polling**: an activity (or sequence) **re-reads** external state until a criterion or timeout, with backoff; the workflow **re-enters** waits via history (timers / retries per options).
2. **Signal** (DUR013): the external system (or adapter) **notifies** the workflow when the condition holds; avoids polling noise when the channel exists.

The choice is **case by case**: latency, signal channel availability, call cost, read idempotence.

### Idempotence and side effects

- **Activities** invoked multiple times (retries) must be **idempotent** or **protected** (idempotency keys on the external service) when the effect is not naturally repeatable (DUR004, DUR011).

### Fragile integrations

- Explicit timeouts, rate limits, and **resilience** (DUR011) in the **activity** or dedicated client.
- Do not encode arbitrary **sleeps** not recorded in workflow history to “wait”: prefer **timers** / **activities** modelled in history.

### Consistency with EventStore

- **Reading** history via EventStore (DUR001) for inspection or audit does not replace **Query** on the workflow for current **business** state: roles differ (raw journal vs query interface).

## Consequences

- Complex integration scenarios must be covered by **tests** (DUR010, DUR015) including simulated replay and retries.
- **Observability** (DUR017) must correlate workflow IDs and external calls to diagnose residual races.
