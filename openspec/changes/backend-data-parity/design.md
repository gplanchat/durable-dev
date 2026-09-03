## Context

Four backends sit behind `WorkflowRunCatalogInterface`. The evidence below is read from the code,
not assumed.

Where the caller's execution name lives, per backend:

- `src/Durable/Store/InMemoryWorkflowRunCatalog.php:107` — `new WorkflowRunDescription($runId, …)`,
  positional, `$runId` being the key of `$this->runs`.
- `src/Bridge/Dbal/Store/DbalWorkflowRunCatalog.php:80` — `runId: (string) $row['execution_id']`.
  `execution_id` is the **primary key** of `durable_workflow_runs`
  (`src/Bridge/Dbal/Schema/DurableSchema.php:96`).
- `src/Bridge/Illuminate/Store/IlluminateWorkflowRunCatalog.php:121` — the same column, the same
  shape, the same cursor encoding.
- `src/Bridge/Temporal/Store/TemporalWorkflowRunCatalog.php:146-160` — `runId` is
  `$execution->getRunId()`, `groupId` is `$execution->getWorkflowId()`. Neither is the caller's name.

Where the caller's name actually is, on Temporal:

- Written at `src/Bridge/Temporal/WorkflowClient.php:300-301` — the raw `$executionId` is encoded
  into the `durableExecutionId` memo field on `StartWorkflowExecution`.
- Read back at `src/Bridge/Temporal/Journal/JournalExecutionIdResolver.php` — from the memo on
  `WorkflowExecutionStarted`, and the resolver throws rather than guessing when it is absent.
- The workflow id is a **lossy** derivation of it (`WorkflowClient.php:263-268`):
  `'durable-' . substr(preg_replace('/[^a-zA-Z0-9._-]/', '-', $executionId), 0, 900)`. Characters
  outside the class collapse to `-` and the result is truncated, so the map is not injective and has
  no inverse.

Where identity is consumed today:

- `src/Durable/Observation/RunDashboard.php:148` — `$run->runId === $selectedRunId`. The admin's
  `?run=` parameter is therefore the caller's name on three backends and a Temporal UUID on the
  fourth.
- `WorkflowRunCatalogInterface::readHistory()` takes the whole description, and its docblock says
  why: Temporal needs the workflow id *and* the run id. That is a fact about history reads, and it
  has been allowed to decide what a run's identity is.

## Goals / Non-Goals

**Goals:**

- One identifier that means the same thing on all four backends, chosen by the caller, stable for
  the life of an execution, and usable as a URL segment.
- A run is openable from that identifier alone, in one round trip, on every backend.
- The properties that make four implementations interchangeable are stated as requirements and run
  as one suite against all four.

**Non-Goals:**

- Changing the wire format. The memo is already written and already read; nothing about in-flight
  executions changes.
- Making `runId` mean the same thing everywhere. It is the backend's own identity and it is right
  that it differs — the mistake was asking it to double as the caller's.
- Replacing the cursor encodings with one shape. Three of them are good; only the property they must
  satisfy is missing.

## Decision

`WorkflowRunDescription::$executionId` carries the caller's name. `runId` keeps the backend's own.
`groupId` is unchanged.

`findRun(string $executionId): ?WorkflowRunDescription` joins the port. Cost per backend:

- In-memory: an array read.
- DBAL and Illuminate: a primary-key select on `durable_workflow_runs`.
- Temporal: `DescribeWorkflowExecution` on `workflowId($executionId)` with an empty run id, then the
  memo read back off the response to recompose the description.

Reading the memo in `listRuns()` costs nothing extra: `WorkflowExecutionInfo::getMemo()` exists
(`src/Bridge/Temporal/Api/Workflow/V1/WorkflowExecutionInfo.php:527`) and `ListWorkflowExecutions`
already returns that message — `describe()` reads it on the line where it already reads the workflow
id.

### Probed and assumed

The house rule is that a server behaviour is probed before it is written as an invariant. This
change has **not** been probed yet, and §0 of `tasks.md` exists to do it before any requirement
below hardens.

**Read from code in this repository, not assumed:**

- The memo is written on start and read back by the resolver (line references above).
- `WorkflowExecutionInfo` carries a `memo` field, so the response type can hold it.
- `execution_id` is the primary key of the runs table, so a lookup is free.
- `workflowId()` is a pure function of the execution id, so the forward direction needs no search.

**Assumed, and to be probed against `temporal server start-dev` before §1 begins:**

1. **An empty `run_id` resolves to the current run of the chain** in `DescribeWorkflowExecution` and
   `GetWorkflowExecutionHistory`. The whole one-round-trip lookup rests on this. `readHistory()`
   currently guards `'' === $run->runId` and returns `[]`, so the component has never exercised it.
2. **`ListWorkflowExecutions` actually populates `memo`** in its response. The proto has the field;
   that the visibility store fills it is a different claim, and standard visibility is known to
   return a reduced projection of an execution.
3. **The memo is not searchable** in standard visibility, which is why the design resolves forward
   through `workflowId()` rather than querying by memo. If it turns out to be searchable, a simpler
   lookup exists and the design should take it.
4. **Continue-as-new preserves the memo** onto the new run. If it does not, `executionId` is absent
   on continued runs and either the client re-posts it or the identity is read from the chain's
   first run.

If (1) is false the lookup needs an extra `ListWorkflowExecutions` call to find the current run id,
which is a cost, not a blocker. If (2) is false, `listRuns()` cannot fill `executionId` without one
`DescribeWorkflowExecution` per row — which **is** a blocker for a list page, and the fallback is to
promote the execution id to a **search attribute** instead of a memo. That fallback changes the
Temporal client and belongs to this change if it is needed; §0.2 decides it.

### Why not derive `executionId` from `groupId` on Temporal

Stripping the `durable-` prefix would recover the name only when it survived sanitisation intact. It
is precisely the ids that did not survive — those holding `/`, `:`, spaces, or exceeding 900 bytes —
whose owners most need a stable address. A derivation that is right most of the time produces an
identifier that is wrong silently, which is worse than one that is absent.

### The collision the identifier inherits

Because `workflowId()` is not injective, `order/17` and `order-17` already start as the same Temporal
workflow, today, before this change. Making the execution id a resource identifier turns a latent
start-time collision into an addressing collision. The narrow fix is to refuse at `startAsync()` any
execution id that sanitisation would alter — the fault is the caller's and the message can say so —
rather than to make `workflowId()` injective with a hash suffix, which would change every workflow
id in flight. §4 carries it.
