# DUR001 — Event store and cursor traversal

## Status

Accepted

## Context

To replay and inspect durable workflow execution history, the component must expose **events** persisted by the orchestrator (Temporal) in a consumable form, without loading the entire history into memory when it is large.

## Decision

The Durable component exposes an **EventStore** that:

1. **Reads** the event history associated with a Temporal workflow (identified in a stable way by the component’s model primitives, e.g. workflow ID, run, namespace per implementation conventions).
2. **Delivers** events as an **iterable list** with **cursor-based pagination**: the consumer receives a batch of events and an opaque cursor to request the next batch until exhausted.

### Principles

- **Total order**: events are traversed in chronological order (or the order defined by the orchestrator) deterministically for replay.
- **Opaque cursor**: the client does not decode the cursor’s internal structure; it passes it back unchanged for the next page.
- **Performance**: avoid offset-based traversal on large histories; the cursor model aims for stable per-request cost.
- **Consistency**: for paginated reads, semantics must avoid duplicates and gaps from concurrent reads as far as the underlying API allows (document behaviour at limits).

### Role in architecture

The EventStore is the **source of truth** for reconstructing workflow behaviour via the state machine (see DUR003): replay reapplies events up to the last executed point.

## Consequences

- Temporal and In-Memory adapters (DUR005) must each provide a consistent implementation of this contract.
- Exposed event types must be rich enough to feed the state machine unambiguously.
