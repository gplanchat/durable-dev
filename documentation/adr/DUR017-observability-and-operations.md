# DUR017 — Observability and operations

## Status

Accepted

## Context

Durable workflows are **long-running**, **distributed**, and **retried**. Without **structured logs**, **metrics**, and **traces**, incident diagnosis and business monitoring are impractical. **Temporal Web UI** and the server API provide native visibility; the component and **activities** must add application-side visibility.

## Decision

### Three pillars

1. **Logging**: discrete events with context (workflow ID, run, activity type, duration) — **PSR-3** compliance for loggers injected into application and activity code.
2. **Metrics**: counters, latency histograms, error rates by operation type; correlation with **workflow ID** as a label when cardinality allows.
3. **Tracing**: when the host enables **distributed tracing** (OpenTelemetry or equivalent), propagate identifiers on **activity → external service** calls (DUR012).

### Activity rules

- **Structured logging**: stable fields (context keys), no sensitive data in clear text (tokens, secrets).
- Log **failures** with business vs system error code or type (DUR011).

### Workflow rules

- No I/O logic; **logs** on the workflow path must stay **deterministic** or absent from user code — the runtime may log **milestones** without breaking replay (DUR003).

### Operations

- **Temporal Web UI**: inspect histories, search by identifiers; use **search attributes** or metadata when the product defines them (optional, outside strict core scope).
- **Runbooks**: procedures for cancellation, manual signal, repair — documented at product level; this ADR sets the **traceability** principle.

### Test vs production alignment

- Log levels in CI may be reduced; the **same** correlation points (IDs) must remain testable in integration tests (DUR015).

## Consequences

- **Logger** dependencies are injected; no hidden global singleton.
- **Observability** does not replace **tests** but speeds diagnosis when a test is missing.
