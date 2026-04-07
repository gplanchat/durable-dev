# DUR011 — Errors, classification, and retries

## Status

Accepted

## Context

**Activities** (DUR004) talk to the outside world; **adapters** to Temporal (DUR002) face network issues, timeouts, and business errors. The **orchestrator** applies **retries** only for some failures. The **workflow** (DUR003) must stay deterministic: it does not handle raw I/O, but it must **react** to failures modelled in history (failed activities, compensations).

## Decision

### Error classification

1. **Business / non-retryable errors** (e.g. validation impossible, resource permanently missing, business rule violated): in principle **no** automatic Temporal retry for that activity; the workflow may run a **compensation** or end in a controlled failure.
2. **System / transient errors** (network timeouts, temporary unavailability, overload): **candidates** for retries with backoff, per policy on the activity or client.

**Exceptions** raised in activities or adapters **must not** cross layers without **translation**: ports exposed to the application domain use component or host domain **error types**, not raw HTTP/gRPC client errors.

### Chaining and context

- Keep **cause** (`previous`) when useful for diagnosis.
- Enrich with **structured context** (workflow ID, activity ID, operation) on **activities** and **workers** — never log secrets in clear text (see project-wide confidentiality rules).

### Retries

- **Parameters** (max attempts, interval, non-retryable exceptions) live in **activity configuration** in the component (stubs, execution options), aligned with Temporal capabilities.
- **Workflows** do not “retry” via side effects: they **replay** history; retries are a property of **activity tasks** and the **engine**.

### Resilience outside the orchestrator

For HTTP or external service calls **inside** an activity, **resilience** patterns (timeouts, rate limiting, circuit breaker) stay **in the activity** or **in a dedicated client**, not in workflow code.

## Consequences

- Tests (DUR009, DUR015) must cover “business vs transient” paths **deterministically** (doubles or controlled backends).
- Operations documentation (DUR017) uses this classification for alerting and diagnosis.
