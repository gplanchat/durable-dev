## Why

A workflow that runs for weeks outlives the deployment that started it. Today this component offers
exactly one way to change such a workflow's code safely, and it is a blunt one: **register a new
workflow type** and keep the old class registered until the old runs drain. `WorkflowRegistry` is
keyed by the type name from `#[Workflow('…')]`, and a run resolves its handler by the type recorded
at start, so `#[Workflow('checkout')]` → `#[Workflow('checkout-v2')]` genuinely works.

It works, and it is expensive. A one-line fix to a branch taken in month two of a six-week run costs
a second class, a second registration, and a drain window nobody can shorten. Temporal's own SDKs
answer this with `getVersion($changeId, $min, $max)` — the PHP SDK included, on
`WorkflowContextInterface` — which lets one class carry both behaviours and lets history decide
which one a given run sees.

This change asks for that primitive, and it asks for it **second**. `workflow-replay-divergence-guard`
comes first: versioning is the sanctioned exception to that guard. A version marker is precisely a
place where the code is *allowed* to diverge from what an older history recorded, and an exception
needs a rule to except.

## What Changes

- A workflow SHALL be able to mark a point where its behaviour changed, and SHALL receive back a
  version that is **stable for the lifetime of an execution**: the same run SHALL see the same
  version on every replay, for as long as it lives.
- The version a run sees SHALL come from its journal, not from the deployed code. A run that
  reached the marker before the change SHALL keep seeing the old version after the new code is
  deployed; a run that reaches it for the first time on new code SHALL see the new one and SHALL
  record that fact.
- The marker SHALL be a legitimate divergence: the replay guard SHALL NOT report it.
- Removing a branch that no live run can still see SHALL be possible without breaking those runs,
  and the way to know whether any can SHALL be observable — a version marker nobody has recorded
  in a live run is one nobody needs.
- The wire representation SHALL be whatever the Temporal server already understands for this, so a
  run started by Durable and inspected in the Temporal UI reads normally. What that is, is the first
  task.
- **BREAKING** no. A workflow that marks nothing behaves exactly as it does today.

### Not in scope

- **Worker-level versioning** — build ids, deployment names, pinning a run to a worker version.
  That is an operational mechanism, it lives in the worker and the task queue rather than in
  workflow code, and it answers a different question.
- **Automatic detection of a behaviour change.** The marker is deliberate. A library that guessed
  which edits were safe would be wrong in the expensive direction.
- **Migrating existing runs** onto a version they never recorded.

## Capabilities

### New Capabilities

<!-- None: this extends the integrity capability the guard introduces. -->

### Modified Capabilities

- `workflow-replay-integrity`: gains the requirement that a declared version marker is a sanctioned
  divergence rather than a reported one, and that a run's version is fixed by its journal.

## Impact

- **Domain** (`src/Durable`): a version marker on the workflow authoring surface; a journal event
  recording the version a run resolved; `ExecutionContext` resolving from history on replay.
- **Temporal bridge**: the marker maps to whatever the server already records for this — task 1.
- **DBAL bridge**: same primitive, journal-local. No asymmetry here: nothing routes anywhere.
- **Replay guard**: the marker is the one place a slot may legitimately differ from history; the
  guard learns about it rather than the marker working around the guard.
- **Test suite**: a run started on old code and replayed on new must keep its version — the test
  that matters, and the one that needs two workflow classes in the same test.
- **ADR**: a new DUR; the comparison page's versioning row stops describing a gap.
- **Dependencies**: `workflow-replay-divergence-guard`, which must land first.
