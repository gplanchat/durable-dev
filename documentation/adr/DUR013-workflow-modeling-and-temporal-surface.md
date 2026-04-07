# DUR013 — Workflow modelling and Query / Signal / Update surface

## Status

Accepted

## Context

Temporal exposes several **surfaces** to interact with a running workflow: **Query** (read state consistent with replay), **Signal** (external input), **Update** (mutation with validation/response semantics per server). The Durable component must **model** these capabilities without the forbidden official SDK (DUR006), while staying aligned with the orchestrator’s **concepts**.

A clear workflow **interface** also improves readability, tests, and type registration with the runtime.

## Decision

### Interfaces and naming

- Each relevant **workflow** is described by an **interface** (or equivalent contract) whose name reflects **intent** (verb + use-case name, e.g. `ProvisionTenant`, `SyncCatalog`).
- **Parameters** of the main entry point are **domain** or component types, **serializable** (DUR007); avoid opaque catch-all DTOs when explicit value objects improve clarity and schema stability.

### Main entry point

- One **main** method represents the durable coroutine start for the scenario (Temporal **WorkflowMethod** vocabulary).
- The implementation **constructor** receives **runtime context** (awaitables, activity stubs — DUR003, DUR004); this rule **takes precedence** over older models where an SDK required a parameterless constructor: here the **Durable runtime** injects context.

### Query, Signal, Update

- **Query**: dedicated methods exposing a **view** of workflow state, **synchronous** from Temporal’s execution model, **no side effects**; serializable, stable return types for observers.
- **Signal**: methods receiving **external** messages; they **mutate** workflow state in a **deterministic**, **replayable** way.
- **Update**: when server and component support them, methods to **propose** a change with validation; semantics (idempotence, response) documented by the component.

These surfaces are **optional** per workflow; **mapping** to Temporal RPCs belongs entirely to **adapters** (DUR012), not workflow business code.

### Determinism

- All logic in Query/Signal/Update handlers follows the same **determinism** rules as the main body (DUR003): no direct I/O, no non-reproducible sources.

## Consequences

- User-facing documentation must list, for each workflow, the exposed **signatures** (main + optional Query/Signal/Update).
- Tests can validate behaviour via the **In-Memory** backend (DUR005) by simulating signals and queries.
