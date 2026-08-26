# DUR037 — Run observation is a projection, and an absent fact stays absent

## Status

Proposed — written alongside the implementation, not yet reviewed.

Follows [DUR030](DUR030-dbal-backend-simplified-durable-execution.md), which added the DBAL backend,
and [DUR031](DUR031-value-objects-across-ports-and-wire-ownership.md), whose value objects are the
reason the dashboard silently stopped reading Temporal.

## Context

The admin dashboard shipped in `gplanchat/durable-plugin` read Temporal, and only Temporal. Its data
provider spoke gRPC and protobuf directly. An application on the DBAL backend installed the plugin,
opened the page, and was told Temporal was unreachable. It was: there was none.

Worse, the plugin required `gplanchat/durable-bridge-temporal`, which requires `ext-grpc`. A Sylius
shop could not even install the package without recompiling PHP.

Making the dashboard read the journal was not a matter of writing a second adapter. **The journal
could not describe a finished run**:

- `ExecutionStarted` carries an execution id and a payload, not the workflow type;
- `ResumeWorkflowHandler` **deletes** the metadata row that does carry the type — on failure, on
  cancellation, and on continue-as-new. Only a successful completion keeps one.

A failed run therefore had no name anywhere, and an operator's dashboard is mostly a list of
failures.

There was also no read surface at all for "which runs exist". `EventStoreInterface` reads one stream
by execution id; `WorkflowMetadataStore` gets one execution.

## Decision

### 1. A read port, `WorkflowRunCatalogInterface`

Listing runs with an outcome filter and a cursor, and reading one run's recorded history. Its
vocabulary is the component's: `WorkflowRunDescription`, `WorkflowRunStatus`, `WorkflowRunEvent`. No
gRPC type and no SQL row crosses it.

`readHistory()` takes the **description**, not an identifier. Temporal addresses a history by
`WorkflowExecution`, whose workflow id is mandatory, and that workflow id is the description's
`groupId`. A port passing only the id would force the caller to find the rest by itself — that is,
to know which backend it is talking to, the one thing the port exists to spare it.

### 2. Observation is a projection, not a query over the journal

`durable_workflow_runs` holds one row per execution. Three options were weighed:

| Option | Why not |
|---|---|
| Put the workflow type on `ExecutionStarted` | Changes a published core event; journals written earlier replay with no name. And it does not solve listing: enumerating runs still means grouping `durable_events` by `execution_id`, on a table indexed by `execution_id` alone. |
| Add a status column to `durable_workflow_metadata` and stop deleting the row | `DurableSchema::addToSchema()` creates missing tables and **never alters** existing ones, and the package ships no migrations — an existing install would never grow the column. The row's *presence* also means "still live" to `hasActiveWorkflowMetadata()` at three call sites. |
| **A dedicated projection table** | Chosen. `addToSchema()` creates it on old and new installs alike; the event shape does not change; the metadata lifecycle does not move. |

The projection is written by two hands because neither knows the whole story: the **name** from
`save()` on the metadata store, the only unambiguous call carrying the workflow type; the
**outcome** from the journal, where the four endings arrive typed and at one site. On the metadata
side, three of those four endings are the same `delete()` call.

The duplication is deliberate. The journal is written on every step and read by execution id — it is
shaped for replay. A dashboard reads across executions and orders by time. Two access patterns, and
a projection is the cheaper way to serve both.

### 3. A fact a backend does not have is absent, never empty

Task queue and namespace exist for Temporal and have no counterpart in SQL. Queries are never
journalled at all — they are answered live and leave no trace. Grouping across a continue-as-new
chain exists for Temporal, whose workflow id survives, and not for DBAL.

Those facts are **omitted** from the description and from the page. An empty "task queue" column
teaches an operator that the run has no queue; an absent column teaches them the backend has no such
notion. Only the second is true.

The same rule applies to lanes: a lane the backend never fills is not rendered empty, it is not
produced.

### 4. Reachability is asked, not assumed

"A catalogue is registered" and "the backend answers" are different questions, and the page asks
both. `checkHealth()` probes what each backend can cheaply probe — the emptiest statement the SQL
dialect accepts, a one-row visibility page for Temporal — and never throws: a failed probe is a
diagnosis, not a caller's fault.

When the probe fails the page lists nothing rather than showing an empty dashboard. An empty
dashboard over a database that is down is the worse of the two errors, because the operator
concludes there is nothing to see.

The health names the backend it probed, so that an operator reading "unreachable" knows what to go
and restart. That is the exact inverse of the no-backend case, where naming a server that was never
involved would send them down a false trail.

### 5. A run that continues as new leaves two independent rows

One that ends, one that starts, and nothing links them. The component already mints a fresh
execution id for the successor and dispatches it as a new run; a link in the projection would be the
only place in the codebase claiming these are one thing. Temporal, which does keep the workflow id
across continuations, carries the link in `groupId` — absent on DBAL, as the rule above requires.

The cost is real and worth naming: on a SQL-backed application an operator cannot follow a workflow
across its continuations.

## Consequences

### Gained

- The dashboard reads whichever backend is configured, and installs without `ext-grpc`.
- A run stays named and dated after it fails, is cancelled, or continues as new.
- `continued_as_new` is no longer reported as a failure. The Temporal provider mapped everything
  that was neither running nor completed to `failed`, so a long workflow turned red at every
  rollover.
- Signals and child workflows land on their own lanes. The provider tested `WORKFLOW_` before
  `SIGNAL` and before `CHILD_WORKFLOW`, and both of those event type names contain `WORKFLOW_`.

### Given up

- **Timeline bars.** Some four hundred lines of viewport geometry are gone; the page groups history
  into lanes of dated events. Every event carries `recordedAt`, so the geometry remains derivable in
  the view — this is a deferral, not a dead end.
- **Free-text search.** It filtered the twenty runs already fetched, so it never found anything past
  the first page, and it searched a task queue the page no longer shows. Real search is a query the
  port does not have.
- **Task queue and namespace on the page**, per §3 above.

### Not decided here

The in-memory backend gets no adapter. Its journal lives and dies with the process serving the
request, so a dashboard over it would show an empty page by construction.

Timers and child workflows are journalled by both backends and still have no lane. Widening the view
in the same change would have made a missing lane indistinguishable from a broken adapter.

## Verification

Everything above is verified at the unit and static-analysis level, against SQLite for the SQL side
and against a mocked gRPC client for the Temporal side. **The page has never been rendered in a
running Sylius application** — the repository carries no bootable Sylius app.

No Temporal server behaviour was probed for this change, and none needed to be: the decisions are
journal-side, and the Temporal half is a move of code already proven against a live server.
