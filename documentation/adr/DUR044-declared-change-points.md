# DUR044 — Declared change points

## Status

Accepted

## Context

A workflow that runs for weeks outlives the deployment that started it. Until now this component
offered exactly one safe way to change such a workflow: **register a new workflow type** and keep
the old class registered until the old runs drain. `WorkflowRegistry` is keyed by the name in
`#[Workflow('…')]` and a run resolves its handler by the type recorded at start, so
`#[Workflow('checkout')]` → `#[Workflow('checkout-v2')]` genuinely works.

It works, and it is blunt. A one-line fix to a branch taken in month two of a six-week run costs a
second class, a second registration, and a drain window nobody can shorten.

[DUR042](DUR042-replay-divergence-guard.md) made the alternative visible rather than silent: a
divergent deploy now fails the workflow task instead of resolving the wrong recorded value. That
turned an invisible hazard into a stopped run — an improvement, and still not a way to *change* a
workflow.

## Decision

**A workflow may declare that its behaviour changed at a named point, and receives back which
behaviour applies to the execution being run.**

```php
if ($this->environment->version('add-discount', ChangePoint::DEFAULT_VERSION, 1) === ChangePoint::DEFAULT_VERSION) {
    $total = $this->await($this->billing->totalWithoutDiscount($cart));
} else {
    $total = $this->await($this->billing->totalWithDiscount($cart));
}
```

### The answer belongs to the execution, not to the deployed code

It is fixed the first time an execution reaches the point, recorded in that execution's journal,
and read back from there on every later replay. A run already in flight keeps its behaviour whatever
is deployed after it — that is the whole difference between versioning and guessing.

Change points are keyed by **id, not by position**, so two of them are independent: an execution can
be on the old side of one and the new side of another.

### An execution older than the point gets `DEFAULT_VERSION`

A run that passed this place before the point was declared holds no marker, and handing it the new
behaviour would be the exact opposite of the intent.

Other SDKs answer this from an "am I replaying" flag their runtime carries. This engine has none —
and does not need one. The question is answerable from the history port as it stands: **if the next
slot of any kind is already recorded, there is work ahead this pass has not reached**, so the call
sits inside the replayed prefix and the execution predates the point.

Deduced rather than stored, which makes it deterministic: two replays of the same history answer the
same. And nothing is written — an old run is not marked, it is recognised.

**Uncovered:** side effects are not consulted, because a recorded side-effect value may legitimately
be `null` and "nothing here" cannot be told from "here, the value null". A workflow whose only work
before a change point is a side effect is treated as new.

### The wire representation is Temporal's, not ours

Probed before being encoded. A versioned workflow written with the **Go SDK** records:

```
EVENT_TYPE_MARKER_RECORDED
  markerName : "Version"
  details    : change-id → json/plain  "<id>"
               version   → json/plain  <n>
```

The bridge emits exactly that, and the server accepts it from a client that is not an official SDK:
the resulting history is byte-identical to Go's. A Durable run therefore reads in the Temporal UI
like any other.

**The search-attribute upsert is part of the primitive, not decoration.** The Go SDK also writes
`TemporalChangeVersion` as a `KeywordList ["<id>-<n>"]`, and that is what makes *which live
executions are still on version N* a query rather than a feature:

```
temporal workflow list --query 'TemporalChangeVersion = "add-discount-1"'
```

Writing the marker without it would work and would silently cost the only practical way to know when
an old branch can be deleted — which is the reason versions are recorded rather than inferred.

### Removal, and the backends that cannot answer

An old branch may be deleted once no live execution can still resolve to it. On the Temporal backend
that is the query above. **On the journal backends there are no search attributes and the question
has no equivalent answer**; the user page says so rather than implying otherwise.

## Consequences

- **The guard did not need teaching.** DUR042 compares what the code asks for against what history
  recorded, and a versioned run asks for exactly what it recorded — because its version came from
  that same history. The sanctioned exception falls out of both mechanisms reading one journal.
- **Versioning one point does not disarm the guard elsewhere.** A different step changed without
  declaring a point still fails the task.
- **Ordering is load-bearing.** The version is decided and journaled *before* the slots it commands.
  Inverted, an execution would use an answer it did not yet have.
- **Every store adapter must round-trip the marker**, and the conformance suite makes them prove it:
  a store that lost it would put an in-flight run on the other branch in silence, with the guard
  seeing nothing wrong.
- **The workflow-type rename stays.** It remains the right answer for a change too large to express
  as a branch, and it is still documented.

## Alternatives considered

- **`#[WorkflowVersion(2)]` on the class.** Reads better and cannot work: versioning is per change
  point, not per class. A workflow amended three times has three independent markers.
- **A version passed as workflow input.** Fixed at start, so a run begun before the change could
  never take the new branch even at a point it has not yet reached.
- **Deriving the version by hashing the code.** Detects that something changed and cannot say what
  the old behaviour *was*, so it cannot replay it.
- **A Durable-owned journal event instead of Temporal's marker.** The fallback the design reserved
  in case the convention could not be reproduced. The probe showed it can, so this was not needed —
  and it would have cost Temporal UI legibility.

## Related decisions

- **DUR042** — the replay divergence guard. This decision is its sanctioned exception.
- **DUR003** — replay and awaitables; the slot model both rest on.
- **DUR041** — store parity as a suite every adapter runs, which now carries the marker round trip.
