## Why

The admin dashboard shipped in `gplanchat/durable-plugin` reads Temporal, and only Temporal. Its
data provider speaks gRPC and protobuf directly: `ListWorkflowExecutions` for the run list,
`TemporalHistoryCursor` for the history of the selected run. An application running the DBAL
backend — durable execution on one SQL database, no cluster (DUR030) — installs the plugin, opens
the page, and is told Temporal is unreachable. It is: there is none.

Making the dashboard read the DBAL journal is not a matter of writing a second adapter. **The
journal cannot describe a finished run.**

- `ExecutionStarted` carries an execution id and a payload. It does not carry the workflow type.
- The workflow type lives in `durable_workflow_metadata`, and `ResumeWorkflowHandler` **deletes
  that row** on failure, on cancellation, and on continue-as-new. Only a successful completion
  keeps a row, via `markCompleted()`.

So a failed DBAL run has no name anywhere: its type died with the metadata row, and the journal
never held it. An operator's dashboard is mostly a list of failures, and failures are exactly what
this drops. That is the reason this is a change to the component rather than a plugin refactor.

The presence of a metadata row is also load-bearing: `hasActiveWorkflowMetadata()` is how the
profiler and the test harness ask "is this execution still live". The row is a live-execution
registry, not history, and three call sites depend on that meaning.

A second gap follows from the first. There is no read surface for "which runs exist" anywhere in
the component. `EventStoreInterface` reads one stream by execution id; `WorkflowMetadataStore` gets
one execution. Listing is new API on a published package, not a refactor of an existing one.

## What Changes

- An operator SHALL see the runs of their application in the dashboard whichever backend records
  them, without installing the Temporal bridge and therefore without `ext-grpc`.
- A run SHALL remain describable — named, dated, and with its outcome — after it has failed, been
  cancelled, or continued as new. Today only successful runs survive.
- The component SHALL expose a read surface for observing runs: listing them with a cursor and a
  status filter, and reading the recorded history of one of them.
- That surface SHALL carry only what a backend can honestly answer. Fields that exist for one
  backend and not the other SHALL be absent rather than invented, and the dashboard SHALL render
  their absence rather than pretend.
- The existing Temporal reading code SHALL move behind that surface, unchanged in behaviour.
- **BREAKING** the dashboard view model renames its `temporal` key to `backend`. The plugin is
  published but unreleased on Packagist; no consumer can be pinned to the old shape yet.
- Not in scope: the in-memory backend. Its journal lives and dies with the process, so a dashboard
  over it would show an empty page in the request that opens it. No adapter is planned.

## Capabilities

### New Capabilities

- `workflow-run-observation`: what an operator can see about the runs an application has recorded —
  which runs exist, what became of each, what its recorded history looks like, and what the view
  does about facts a given backend cannot supply.

### Modified Capabilities

<!-- None: no existing documented requirement changes. -->

## Impact

- **Domain** (`src/Durable`): a read port for observing runs, and the run description it returns.
- **DBAL backend** (`src/Bridge/Dbal`): a projection of run lifecycle, and the adapter over it.
  `durable_events` is indexed on `execution_id` alone, so ordering a run list by time would be a
  scan and a sort — the projection exists partly to avoid that.
- **Temporal backend** (`src/Bridge/Temporal`): the dashboard's gRPC reading code moves here and
  implements the port. Behaviour unchanged; it is a move, not a rewrite.
- **Bundle** (`src/DurableBundle`): registers whichever adapter the configured backend provides.
- **Plugin** (`src/DurablePlugin`): depends on the port instead of the Temporal bridge, and its
  template renders absent facts instead of assuming Temporal's.
- **ADR**: DUR035 records why run observation is a projection rather than a query over the journal,
  and why an absent fact is modelled as absent rather than as an empty string.
- **Dependencies**: none added. The plugin loses one.
