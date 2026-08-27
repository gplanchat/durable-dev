# Design

## What was probed, and what was assumed

**Probed: nothing. Assumed: the wire representation.** Temporal records a version marker as an event
in workflow history, and other SDKs read it back on replay. What that event is called, what it
carries, and whether a server accepts it from a client that is not an official SDK are three
questions this design cannot answer from the protobuf definitions — the `Marker` machinery is
generic, and the meaning is a convention between SDK and SDK, not a server rule.

That convention is exactly the kind of thing the house rule exists for. Six wrong assumptions have
already been corrected by probing; this would be the seventh if written from belief.

**Task 1 is the probe**, and it has a fallback the probe may force: if the server's marker
convention cannot be reproduced faithfully, the version lives in a Durable-owned journal event
instead. That works, and it costs the property that a Durable run reads normally in the Temporal UI.
Which of the two we get is a finding, not a preference.

```
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

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
