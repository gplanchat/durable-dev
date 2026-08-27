# Design

## What was probed, and what was measured

**Probed: the whole convention, against `temporal server start-dev` 1.31.2.** A versioned workflow
was written with the official **Go SDK** (`workflow.GetVersion(ctx, "ajout-remise", DefaultVersion, 1)`),
run, and its history dumped. Then the same marker was emitted from the Durable bridge and the two
histories compared.

### What the server records

```
EVENT_TYPE_MARKER_RECORDED
  markerName : "Version"
  details    : change-id → json/plain  "ajout-remise"
               version   → json/plain  1
```

That is the whole convention: a `RECORD_MARKER` command, one fixed marker name, two named payload
lists in `details`. Nothing exotic, and nothing the server itself interprets.

### The server accepts it from us — the fallback is not needed

The bridge emitted exactly that marker and `RespondWorkflowTaskCompleted` was **accepted**. The
resulting history is byte-identical to the Go SDK's on all three fields: marker name, change id,
version.

Task 1.3's fallback — a Durable-owned journal event, at the cost of Temporal UI legibility — is
therefore **not needed**. A Durable run using this primitive will read in the Temporal UI exactly
like a Go one.

The machinery also already exists: `TemporalWorkflowCommandBuffer` emits `COMMAND_TYPE_RECORD_MARKER`
with a `map<string, Payloads>` for side effects and for cancellation delivery. The version marker is
the same call with a different name and two entries.

### The one difference, and it settles a later task

The Go SDK writes **one more event** that we did not:

```
EVENT_TYPE_UPSERT_WORKFLOW_SEARCH_ATTRIBUTES
  TemporalChangeVersion : KeywordList  ["ajout-remise-1"]
```

`TemporalChangeVersion` is a **standard** Temporal search attribute, and it is queryable as it
stands:

```
temporal workflow list --query 'TemporalChangeVersion = "ajout-remise-1"'
```

This is the answer to "which live executions are still bound to a given behaviour of a change
point" — the question task 4.3 was going to investigate. **It is a query, not a feature**, provided
the marker is accompanied by that upsert. Writing the marker without it would work and would silently
cost the only practical way to know when an old branch can be deleted.

So the upsert is part of the primitive, not an optional extra. Task 2.2 says so.

## The shape of the primitive

Whatever the surface ends up being, three properties are non-negotiable and every alternative below
is judged against them:

1. **A run's version never changes.** Fixed at first encounter, read from history forever after.
2. **A run that never reached the marker is not bound by it.** It resolves on the deployed code the
   first time it gets there.
3. **The marker is not a divergence.** The guard has to know about it, or the guard fires on every
   versioned workflow — which would make the two changes mutually exclusive instead of sequential.

## Why not an attribute

`#[WorkflowVersion(2)]` on the class reads better than a call in the middle of a method, and it
cannot work: versioning is per **change point**, not per class. A workflow amended three times has
three independent markers, and a run may sit on the old side of one and the new side of another.
The primitive has to be positional because the problem is.

## The interaction with the guard, stated plainly

The guard compares recorded identity to requested identity at each slot. A version marker changes
what the code requests at slots *after* it — that is its entire purpose. So the guard cannot simply
be told "ignore markers"; it has to accept that the branch a run takes is itself a recorded fact,
and compare against what that run recorded.

Concretely: the version event has to be journaled **before** the slots whose identity it decides,
and read back before them on replay. If that ordering is wrong, a run resolves its version after
using it, which is the same bug the guard exists to catch, in the mechanism meant to sanction it.

## Removing an old branch

The reason to record versions rather than infer them is that removal has to be safe. A branch may be
deleted once no live run can still resolve to it, and "can still" is a question about journals, not
about calendars. Whether the existing run observation projection can answer it — *which live runs
recorded version 1 of change point X* — is worth checking before inventing anything: if it can, the
answer is a query, not a feature.

## Alternatives considered

- **Do nothing; the new workflow type is enough.** It is the honest baseline and it is what we ship
  today. It costs a drain window per change, and for a workflow that runs six weeks that is six
  weeks of two classes. Acceptable once, not as the permanent answer.
- **A version passed as workflow input.** Fails property 1 the moment somebody signals a running
  workflow, and fails property 2 entirely: input is fixed at start, so a run started before the
  change can never see the new branch even at a marker it has not yet reached.
- **Deriving the version from the code and comparing hashes.** Detects that something changed,
  cannot say *what* the old behaviour was, and therefore cannot replay it.
