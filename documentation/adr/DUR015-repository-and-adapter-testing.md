# DUR015 — Repository, adapter, and test data

## Status

Accepted

## Context

Command and Query **ports** (DUR002) and their **adapters** (DUR012) are central to Temporal integration. **Errors** (DUR011) and **serialization** (DUR007) must be validated **reliably** and **repeatedly**. General testing strategy is in DUR009 and DUR010; this ADR scopes **repository / adapter / test data**.

## Decision

### Goals

- Verify each **adapter** respects the port **contract** (mapping, translated errors, call chains).
- Guarantee **determinism**: same simulated inputs → same outputs and same expected error types.
- **Isolate** tests from real network dependencies when possible (**In-Memory** backend DUR005, or fake protocol client).

### Test data

- Use dedicated **fixtures** or **builders** for entities and identifiers; avoid unfixed random IDs.
- Prefer **minimal** datasets per scenario: explicit **load** and **cleanup** or **reset** between tests when the implementation holds mutable state.
- For tests requiring a **real Temporal** server, keep their number small (DUR010) and document prerequisites.

### Strategy by target

| Target | Preferred approach |
|--------|-------------------|
| Repository adapter + fake client | Light unit/integration tests, assertions on calls and mappers |
| Full In-Memory backend | Integration tests without network |
| Low-level client alone | Tests with recorded responses or transport stub |

### Test doubles

- Prefer deterministic **fakes** and **fixtures** over infinitely configurable mocks (aligned with DUR009: no opaque behaviour driven by dynamic setters).

### Error coverage

- **Success** and **failure** cases (business vs transient, DUR011) for at least one representative operation per critical port.

## Consequences

- **META** documents may show example `tests/` layout without duplicating these principles.
- **Mapping** regressions are caught early if tests stay **stable** and **fast**.
