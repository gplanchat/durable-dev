# DUR030 — DBAL backend: simplified durable execution on a single SQL database

## Status

Proposed — written alongside the implementation, not yet reviewed.

Extends [DUR005](DUR005-implementation-backends-temporal-and-in-memory.md), which states that a third
implementation backend requires a new ADR. Follows [OST001](../ost/OST001-alternative-durable-execution-backends.md) §5.

## Context

DUR005 put exactly two backends in scope: **In-Memory** (tests, local work) and **Temporal**
(production). That leaves a gap that OST001 identified while surveying the market: a team that wants
durable execution in production but does not want to operate a Temporal cluster — or install
`ext-grpc` on every machine — has nothing to run. The nearest PHP competitor
(`durable-workflow/workflow`, ex `laravel-workflow`) sells precisely that: no server, no cluster.

The survey also found that Durable already owns every piece needed to be its own engine:

- a deterministic replay interpreter (`WorkflowFiberDriver`) that is backend-agnostic;
- `EventStoreCommandBuffer`, `EventStoreHistorySource` and `EventStoreWorkflowLifecycle`, which
  already implement the three workflow ports over `EventStoreInterface`;
- `EventDataMapper`, whose docblock already describes the journal record shape as "rows in
  `InMemoryEventStore` **or any other `EventStoreInterface`**";
- a configuration tree that already reserves `durable_events`, `durable_workflow_metadata` and
  `durable_child_workflow_parent_link` as table names.

What is missing is persistence. Only three ports are process-local today: `EventStoreInterface`,
`WorkflowMetadataStore`, `ChildWorkflowParentLinkStoreInterface`.

## Decision

Add a third backend, `gplanchat/durable-bridge-dbal`, implementing those three ports over
**Doctrine DBAL**. Selected by `durable.event_store.type: dbal` (and the matching
`workflow_metadata` / `child_workflow.parent_link_store` types).

### What "simplified" means — the explicit reduction

This backend is **not** a Temporal replacement. Compared to the Temporal backend it gives up:

| Given up | Consequence |
|---|---|
| Distributed task queues | Resumes ride Symfony Messenger; throughput is the transport's, not a cluster's. |
| Server-side scheduling and timers | Timers ride Messenger `DelayStamp` via `FireWorkflowTimersHandler`. |
| Server-side task serialisation per execution | Replaced by an application-level lock — see below. |
| Cluster-side history durability, retention, visibility | One SQL table; retention and purge are the application's job. |
| The Temporal UI and its operational tooling | The Durable profiler only. |

What it keeps: the **same authoring model**. Workflow classes, activities, `WorkflowEnvironment`,
signals, queries, updates, cancellation and compensation behave as on the other two backends
(DUR022, DUR023). That is the point — the reduction is operational, never in the programming model.

### Concurrency — the decision that shapes the component

Temporal serialises workflow tasks for one execution server-side. Without a server, two Messenger
consumers can dequeue two resumes of the same execution and replay the same fiber in parallel,
each appending its own commands: duplicated activities, forked journal.

Two options were considered:

1. **Optimistic concurrency through the port** — add an expected-version parameter to
   `EventStoreInterface::append()`. Rejected: it changes a **core** port for the benefit of one
   backend, and detecting a conflict after the fact does not prevent the duplicate side effects an
   activity has already caused.
2. **Application-level mutual exclusion** — a per-execution lock held for the whole
   replay-and-append cycle. **Chosen.** It is bridge-local, needs no core change, and matches where
   the invariant actually lives (one resume at a time, not one append at a time).

Implemented as `SingleResumeLockMiddleware`, a Messenger middleware registered only when the DBAL
event store is active, taking a `symfony/lock` lock named `durable-resume-{executionId}` around
`ResumeWorkflowMessage` and `FireWorkflowTimersMessage`. Acquisition is **blocking**: the second
worker waits rather than requeuing. The lock store is the application's (`lock.factory`); a
DBAL or Redis store makes it cross-process, an in-memory one does not.

Consequence to state plainly: **this backend is only as safe as its lock store.** A single-process
lock store with multiple workers gives the duplicated-journal failure this middleware exists to
prevent.

### Schema

Three tables, created lazily on first write by `DurableSchema::ensure()`, following the Symfony
Messenger Doctrine transport pattern. **No `doctrine/migrations`** — the shape is fixed by
`DurableSchema` and the table names stay configurable.

The journal table has no `sequence` column: `readStream()` promises insertion order and the
auto-increment primary key carries it. With the resume lock in place there is one writer per
execution at a time, so no ordering ambiguity arises.

### Mutual exclusion with Temporal

`event_store.type: dbal` together with a non-empty `temporal.dsn` throws at container compile time.
The journal cannot have two sources of truth.

## Consequences

- A third backend exists; DUR005's "no third backend" clause is superseded by this ADR for the SQL
  case only. Any **fourth** backend still requires its own ADR.
- `doctrine/dbal` and `symfony/lock` enter the dependency graph, but only for applications that opt
  into this bridge — the core and the bundle keep no hard dependency on either.
- The In-Memory backend remains the test backend. The DBAL backend is tested against SQLite
  in-memory, which exercises real SQL without requiring a server.
- `tests/unit/Bridge/Dbal/DbalBackendParityTest.php` runs one workflow (activity, timer, two side
  effects including a nested non-scalar payload) against both journals and asserts the recorded
  events and the replay slot lookups match. Extending that to child workflows, cancellation and
  continue-as-new is follow-up work.

## Related

- [DUR001](DUR001-event-store-and-cursor.md) — event store and cursor
- [DUR005](DUR005-implementation-backends-temporal-and-in-memory.md) — the two-backend scope this extends
- [DUR007](DUR007-serialization-and-symfony-serializer.md) — serialization policy; `EventDataMapper` is the boundary
- [DUR021](DUR021-symfony-messenger-integration.md) — Messenger resumes, which this backend rides on
- [OST001](../ost/OST001-alternative-durable-execution-backends.md) §5 — where this component was proposed
