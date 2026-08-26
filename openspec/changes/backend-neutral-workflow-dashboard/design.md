## What was probed, and what was assumed

**Nothing was probed against a running Temporal server for this change, and nothing here needs it.**
The house rule exists because server rules encoded as invariants have been wrong six times; this
change encodes none. Its Temporal half is a move of code already proven against a live server
(`ListWorkflowExecutions`, `TemporalHistoryCursor`), and its new decisions are all journal-side —
they concern what the component records, not what the server enforces.

Two facts below were read from the code, not assumed, and each is load-bearing:

- `ResumeWorkflowHandler` deletes the metadata row on failure, cancellation and continue-as-new
  (three `delete()` calls); only the success path calls `markCompleted()`.
- `DurableSchema::addToSchema()` creates missing tables and never alters existing ones. There is no
  doctrine/migrations. An install that already has `durable_workflow_metadata` will never grow a
  column on upgrade.

## Where the run description comes from

Three ways to make a finished DBAL run describable. They are not equivalent.

| Option | What it costs |
|---|---|
| **a.** Put the workflow type on `ExecutionStarted` | Changes the shape of a published core event. Journals written before the change replay with no name, so the reader needs a documented fallback rather than a crash. And it does not solve listing: enumerating runs would still mean grouping `durable_events` by `execution_id`, on a table indexed by `execution_id` alone. |
| **b.** Stop deleting the metadata row, add a status column | The metadata row's *presence* means "still live" at three call sites (`hasActiveWorkflowMetadata()` in the data collector and the test harness). Turning it into history changes that meaning everywhere. Worse, `addToSchema()` cannot add a column to a table that already exists: every existing install would keep the old shape and the new code would query a column that is not there. |
| **c.** A dedicated run projection table | One new table. `addToSchema()` creates missing tables, so it appears on old and new installs alike. The event shape does not change, the metadata lifecycle does not change, and nothing coupled to `hasActiveWorkflowMetadata()` moves. Cost: a projection must be written and kept in step with the lifecycle. |

**Decision: (c).** It is the only option that is safe on an install that already exists, and that
constraint is not negotiable — `ensure()` cannot ALTER, and the package ships no migrations. The
projection is written on the same lifecycle transitions that already touch the metadata store, so
"kept in step" means one more call beside calls that are already there, not a new subscriber to
maintain.

## Where the projection is written

Seven sites touch the metadata store. Four of them end a run.

| Site | What it means |
|---|---|
| `MessengerWorkflowResumeDispatcher::dispatchNewWorkflowRun()` — `save()` | a run starts, journal backend |
| `TemporalWorkflowResumeDispatcher::dispatchNewWorkflowRun()` — `save()` | a run starts, Temporal backend |
| `ResumeWorkflowHandler:89` — `save()` | the successor of a continue-as-new starts |
| `ResumeWorkflowHandler:86` — `delete()` | the run that continued as new **ends** |
| `ResumeWorkflowHandler:97` — `delete()` | the run was **cancelled** |
| `ResumeWorkflowHandler:102` — `delete()` | the run **failed** |
| `ResumeWorkflowHandler:108` — `markCompleted()` | the run **completed** |

**A decorator on `WorkflowMetadataStore` cannot carry this.** Three of the four endings are the same
call — `delete()` — and it means continued-as-new, cancelled, and failed at the three sites. A
decorator sees one method and cannot tell them apart, which is exactly the distinction the dashboard
exists to show.

**Decision: two writers, each writing only what it alone knows.**

- **The name comes from the metadata store.** `save()` is unambiguous and carries the workflow type;
  a decorator seeds the projection row there. `delete()` is never consulted, so its ambiguity costs
  nothing, and the metadata lifecycle is untouched — which the proposal requires.
- **The outcome comes from the journal.** `EventStoreWorkflowLifecycle` already appends
  `ExecutionCompleted`, `WorkflowExecutionCancelled`, `WorkflowContinuedAsNew`, and a classified
  `WorkflowExecutionFailed` — the four endings, each with its own type, at one site. The DBAL event
  store settles the projection row when it appends one of them.

Two properties fall out, and both are why this shape was chosen over writing at the seven sites.
`EventStoreWorkflowLifecycle` is the journal-backed lifecycle; Temporal runs
`TemporalWorkflowLifecycle` instead, so the outcome writer never fires on a Temporal-backed
application. And a future ending that forgets the projection is a lifecycle event that was never
journalled — which replay would already have caught long before the dashboard did.

## Continue-as-new records two independent runs

**Decision: two rows, not a chain.** A run that continues as new ends, and the run that takes over
starts; the projection records each on its own terms, and neither points at the other.

This costs something and it is worth naming: on a DBAL-backed application an operator cannot follow
a workflow across its continuations. They see the run that ended and the run that took over as two
entries, and nothing tells them the second came from the first.

It is nonetheless the shape that matches what already exists. Continue-as-new in this component is
already a new execution and not a continued one — `ResumeWorkflowHandler` mints a fresh execution
id, saves fresh metadata under it, and dispatches it as a new run. A chain in the projection would
be the only place in the codebase claiming these are one thing, and it would need a link column and
a view affordance to mean anything.

It also lands exactly on the port's grouping id. Temporal keeps the workflow id across
continuations and gives each run its own run id, so a Temporal-backed dashboard *can* group them —
and does, through that optional field. The DBAL backend has no such concept, so it leaves the field
absent. That is the same rule as everywhere else here: absent, not invented. The chain is a fact
one backend has and the other does not, and the view says so rather than faking a link.

## What each backend can honestly answer

The template consumes exactly these. This is the table the port is cut from.

| View fact | Temporal | DBAL | Note |
|---|---|---|---|
| `runId` | run id | execution id | The port carries **one** identity plus an optional grouping id. Temporal's workflow id becomes that optional field; DBAL leaves it absent. Two mandatory ids would force DBAL to duplicate one value into both. |
| `workflowName` | workflow type | projection | The reason this change exists. |
| `status` | execution status | derived | `ExecutionCompleted`, `WorkflowExecutionFailed`, `WorkflowExecutionCancelled` are all journalled; `failed` is representable. |
| `startedAt`, `duration` | execution info | `recorded_at` bounds | |
| `taskQueue` | task queue | **absent** | No per-execution queue exists in the DBAL model. |
| `events` | history events | journal events | Different vocabularies, same role. |
| `timeline` — execution, activity, signal, update lanes | yes | yes | Domain events exist for each. |
| `timeline` — query lane | yes | **absent** | No query is ever journalled; `src/Durable/Event/` has no query event. Queries are answered live and leave no trace. |
| `backend.namespace` | namespace | **absent** | |
| `backend.connected`, `checkedAt`, `lastSuccessfulAt` | gRPC reachability | database reachability | Same question, both can answer it. |
| `backend.message` | yes | yes | |

Absent means **absent**, not `''` and not `'n/a'`. A view that renders an empty task queue column
teaches the operator that the run has no queue; a view that omits the column teaches them the
backend does not have the concept. The second is true.

The view model's `temporal` key is renamed `backend`. It is read by name in the Twig template, so
the template changes with it; that is in scope.

Two lanes the Temporal dashboard never showed are journalled by both backends — timers and child
workflows. They are **not** added here. This change is about backend neutrality, and widening the
view at the same time would make it impossible to tell a missing lane from a broken adapter.

## Why not a query over the journal

The projection duplicates facts the journal already holds, which is worth justifying. Listing runs
from `durable_events` means grouping by `execution_id` over a table whose only index is
`execution_id` — every page of the run list is a scan and a sort, growing with the total number of
events ever recorded, not with the number of runs shown. The journal is written on every step of
every execution and read by execution id; it is shaped for replay. A dashboard reads across
executions and orders by time. Those are different access patterns, and the projection is the
cheaper of the two ways to serve both.
