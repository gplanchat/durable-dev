# DUR016 — In-Memory backend: storage rules and exceptions

## Status

Accepted

## Context

The **In-Memory** backend (DUR005) must provide a **faithful** port implementation for tests while staying **simple**. A **reference** implementation may use rich structures (indexes, tag invalidation, collections) to match persistent systems. In some cases **minimal** storage (arrays, maps) is enough and reduces complexity.

## Decision

### Default rule

- The component’s **reference** In-Memory implementation uses storage **consistent** with test needs (search, invalidation, traversal) — details in code and comments.

### Allowed exceptions: simplified storage

An In-Memory module **may** use **minimal** storage (e.g. in-memory `array`) **if and only if** a majority of the following holds:

1. **Limited role**: data is mostly **read**, few writes or writes only at test startup.
2. **Static or configuration data**: fixed set or loaded once, not a full relational simulation.
3. **No need** for advanced features (fine invalidation, complex queries) for the covered scenario.
4. **Clarity**: code is more readable than the generic layer for that specific case.

### When to use the rich implementation again

- Entities **written often**, **shared** across tests, or scenarios needing **selective** cleanup.
- Need for **consistency** with behaviours close to **cache** or simulated **storage**.

### Documentation

- Any “exception” In-Memory class **must** state in a docblock or short note **why** simplified storage is justified (implicit reference to this ADR as “DUR016”).

### Migration

- If minimal storage becomes insufficient (frequent writes, invalidation needs), **refactor** to the reference implementation without changing **ports**.

## Consequences

- Code reviews check that exceptions do not **multiply** without real need.
- Tests (DUR015) stay **isolated**: reset or fresh instances per test when state is mutable.
