# DUR043 — The projection is a port, and the in-memory backend reads its own runs

## Status

Accepted

## Context

DUR037 made run observation a **projection**: the dashboard reads a `WorkflowRunCatalogInterface`,
never a journal. On the SQL side that projection is written by two decorators —
`ProjectingEventStore` seeds outcomes from the journal, `ProjectingWorkflowMetadataStore` seeds the
workflow name from `save()` — both typed against the concrete `DbalWorkflowRunProjection` and both
living in the DBAL bridge.

That left the in-memory backend with no catalog at all. `DurableExtension` registered one for DBAL
and one for Temporal; with neither configured, `WorkflowRunCatalogInterface` had no alias and the
dashboard rendered "no readable backend is configured".

**That was a deliberate decision, and its argument is worth restating before it is overturned.**
`DurableRunCatalogWiringTest` carried it: wiring a catalog that reads nothing would show an *empty
page* where an operator must read *no readable backend*, and an in-memory journal is precisely that
case — it lives and dies with the process serving the request.

Two things changed. `InMemoryWorkflowRunCatalog` now exists and passes the DUR041 conformance suite,
so the backend *can* answer. And the plugin's claim to be backend-neutral was true of two backends
out of three, which is a claim with an asterisk nobody had written down.

## Decision

### The projection is a port

`WorkflowRunProjectionInterface` — `recordStart()`, `recordOutcome()` — lives in
`Gplanchat\Durable\Observation`. `DbalWorkflowRunProjection` implements it. So does
`InMemoryWorkflowRunCatalog`, which is **its own projection**: in memory there is no transaction to
share and no concurrent reader to serve, so splitting write from read would cost an interface and
two decorators for nothing.

**`ProjectingEventStore` and `ProjectingWorkflowMetadataStore` move into the core**, from
`Gplanchat\Bridge\Dbal\Store` to `Gplanchat\Durable\Store`, typed against the port. Neither ever
touched a `Connection`; they were in the bridge by accident of where they were first written. The
core cannot depend on a bridge, so the choice was to move them or to write an in-memory copy of
both — a second implementation of the rule that says which four events end a run. **This is a
breaking change for `gplanchat/durable-bridge-dbal`**, taken before 1.0, and it is the second one
today after `JournalRunHistoryReader` for exactly the same reason.

### The in-memory catalog is wired last, and only if nobody claimed the slot

`registerInMemoryRunCatalog()` runs at the end of `load()` and returns immediately if
`WorkflowRunCatalogInterface` already has an alias. DBAL and Temporal keep their own.

The catalog reads the **undecorated** journal for history while the decorator feeds it on write —
both point at `durable.event_store.inner` rather than at each other, or the container would cycle.
That is the wiring's real risk, and it is what the new test asserts: three correct services pointing
at one catalog and one journal, not at two of each.

### The empty list explains itself, because the old objection was half right

Under PHP-FPM the request that renders the dashboard has executed no workflow, so the in-memory list
**will** be empty on a perfectly healthy application. That half of the original argument still
holds, and hiding it would repeat the mistake DUR037 exists to prevent — an empty column teaching an
operator that a run has no queue, when it is the backend that has no queues.

So `checkHealth()` does not answer a bare "reachable". Its message says that this catalog only ever
sees runs from its own process, and that an empty list means nothing ran *here*, not that nothing
ran. The plugin already renders `backend.message`; the sentence reaches the page with no template
change.

Where the catalog earns its keep is a long-running process — a FrankenPHP worker, a consumer
command, a test — and that is stated in the plugin README rather than left to be discovered.

## Consequences

- **The plugin's backend-neutrality claim is now true of three backends**, not two with an
  unwritten asterisk.
- **`ProjectingEventStore` / `ProjectingWorkflowMetadataStore` change namespace.** Breaking for the
  DBAL bridge; every reference in this repository moved with them.
- **A test that asserted the opposite is rewritten, not deleted.** `DurableRunCatalogWiringTest`
  keeps the original argument in its docblock, marked as the half that still holds and the half
  that no longer does. A later reader finds a decision rather than a reversal without a reason.
- **The "no readable backend" branch of the plugin is now unreachable through this bundle**, since
  the fallback always registers something. The branch stays: a backend that registers no catalog is
  still expressible, and the page should keep saying so rather than render a page about nothing.
- **A third implementation of the projection port is now cheap.** Whatever writes runs — a Laravel
  adapter, a future backend — implements two methods and reuses both decorators.

## References

- [DUR037 — Run observation is a projection, and an absent fact stays absent](DUR037-run-observation-as-a-projection.md)
- [DUR041 — Store parity is a suite every adapter runs](DUR041-store-parity-is-a-suite-every-adapter-runs.md) — the suite `InMemoryWorkflowRunCatalog` had to pass first.
- [DUR030 — DBAL backend: simplified durable execution on a single SQL database](DUR030-dbal-backend-simplified-durable-execution.md)
