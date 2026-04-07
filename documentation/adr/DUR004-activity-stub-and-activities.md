# DUR004 — ActivityStub, activities, and activity methods

## Status

Accepted

## Context

**Workflows** (DUR003) are deterministic and perform no direct I/O. **Side effects** (databases, HTTP, files, etc.) live in **activities**: user-provided classes executed in an environment where I/O and non-determinism are allowed, under the orchestrator’s retry policies.

The workflow must **invoke** these activities through a stable abstraction: the **ActivityStub**, which routes calls to Temporal infrastructure (or its In-Memory simulation) while keeping PHP types on the workflow author side.

## Decision

### Activities

- Activities are **classes** written by the component user.
- They **may** perform **I/O** and use injected services (DI), within limits set by the host.
- **Methods** exposed as activity entry points are marked with a component attribute, e.g. **`#[ActivityMethod]`** (final name aligned with the implementation).
- **Argument** and **return** types must be **serializable** through the component pipeline to Temporal (no resources, unsupported closures, etc.). The mechanism is described in **DUR007** (Symfony **Serializer**).

### ActivityStub

- Inside the **workflow**, the author does not instantiate the activity directly for durable effects: they use an **ActivityStub** (or equivalent factory) that:
  - **routes** method calls to the corresponding **activity** on the worker / orchestration side;
  - ensures the call is modelled as a durable step (recorded in history, replayable).

### Workflow vs activity separation

| Workflow | Activity |
|----------|----------|
| Deterministic, no direct I/O | I/O and non-deterministic logic allowed |
| Awaitable context + fibers (DUR003) | “Normal” execution on the worker |
| Logical idempotence of the orchestration graph | Operational idempotence recommended for retries |

### Relationship to stubs

- A **stub** is bound to an **activity type** (interface or class) and lets the author invoke methods marked as **durable commands** from the workflow.
- Binding resolution (Temporal activity name, timeouts, retries) is handled by component configuration and adapters.

## Consequences

- Activity payload serialization is a public contract (see **DUR007**): any evolution must preserve backward compatibility or define migration strategies.
- META documents can detail attribute patterns, naming, and registration without diluting this ADR.
