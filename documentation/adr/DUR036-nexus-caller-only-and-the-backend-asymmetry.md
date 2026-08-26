# DUR036 — Nexus is supported on the caller side only, and one backend can serve it

## Status

Accepted

## Context

Temporal Nexus lets a workflow call an operation owned by another team, another namespace, another
deployment. Two roles exist, and they are not symmetric: **calling** an operation, and **serving**
one. This record covers what was decided about the first, and why the second is deliberately not
here.

Four things were measured against a running Temporal 1.31.2 before anything was written. They are
the reason the decisions below took the shape they did, and each is pinned by an integration test
so a change of server behaviour is caught rather than discovered.

**The three names of one call follow three different rules.** The endpoint is validated by the
server: `^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$`, 200 characters, refused outright at creation — a
single letter is refused too, the pattern needing a first *and* a last character. The service and
the operation are validated by nothing at all: empty, a single space, an inner tab, a control
character, a thousand characters — every one accepted, and recorded verbatim in
`NEXUS_OPERATION_SCHEDULED`.

**An unknown endpoint is not a failure the workflow can catch.** `RespondWorkflowTaskCompleted` is
rejected with `INVALID_ARGUMENT`, history records `WORKFLOW_TASK_FAILED` with cause
`BAD_SCHEDULE_NEXUS_OPERATION_ATTRIBUTES`, and the workflow task is re-served with its `attempt`
climbing — measured to 4 and still going. No `NEXUS_OPERATION_SCHEDULED` is ever written, so there
is no operation to fail. The workflow does not fall over; it spins.

**A sub-bound larger than the envelope is silently rewritten.** Asking for 60 s of `startToClose`
under 10 s of `scheduleToClose` records 10 s, without an error. A negative duration is refused, and
the message names the field. Zero means *unbounded*, not *zero seconds*, and clamps nothing.

**A workflow task can carry several journaled messages, but not reliably.** Three signals sent to a
task queue nobody polls arrive in one task; the same probe with a worker polling shows one signal
per task. How many messages a task carries is a timing artefact of worker availability, not a
contract.

## Decision

### The caller side only. Serving Nexus operations is a separate change.

A handler needs a Nexus task worker, its own poll loop, its own dispatch and its own failure
vocabulary — `PollNexusTaskQueue`, `RespondNexusTaskCompleted`, `RespondNexusTaskFailed`, none of
which the caller path touches. Bundling them would double the surface and halve the review.

### One backend can serve Nexus, and the other says so out loud.

Nexus routes a call to an endpoint served elsewhere. A backend keeping its journal in one database
has no such route, and **no honest fallback**: it can neither perform the call nor drop it without
leaving the workflow waiting on a result nobody will produce. So the journal backend **refuses**,
immediately, with an exception that names the backend and says what to do instead.

This is the asymmetry, and it is not a gap to close later. Every other capability of this component
works on both backends; this one cannot, by its nature. Pretending otherwise would reproduce the
failure mode this codebase treats as the most expensive — the silent wait.

### The value objects are stricter than the server exactly where the server is not.

`NexusEndpoint` mirrors the server's rule and invents nothing: the server refuses a malformed name
at creation, loudly, so a stricter rule would reject valid names and prevent no mistake.

`NexusService` and `NexusOperationName` are stricter, for the same reason `TaskQueue` is: the server
accepts what can only be a mistake, and the mistake costs an operation that waits forever for a
handler whose name will never match. They refuse empty, blank, edge whitespace and control
characters — and **nothing else**. No length limit, no alphabet: none was observed, and an invariant
that was not measured has no business being enforced. `com.example.checkout` is a legitimate name.

`NexusOperationTimeouts` refuses a sub-bound larger than the envelope, which the server would clamp
without a word. This is the one place the object is stricter than the server on a *combination*
rather than a value, and it is the difference between a bound one believes one has and a bound one
has.

### No `executionBoundOr()`, unlike activities.

Activities have it because the server requires a closing bound and the bridge must produce one. The
probe showed Nexus requires none — a command carrying none of the three is accepted, and the event
records none. A fallback toward a bound nobody demands would be dead code wearing the look of a
precaution.

### An unknown endpoint is a caller-side check or an accepted loop, not a typed failure.

The measurement forecloses the third option. A validated `NexusEndpoint` does not help: the name is
well formed, it is the endpoint that is missing. Either the caller verifies the endpoint exists
before emitting the command, or the retry loop is accepted knowingly and documented as the failure
mode of a misconfigured endpoint. What must not happen is a promise of a typed failure that the
server never delivers.

## Consequences

- An application on the journal backend that calls a Nexus operation fails fast, at the call, with
  an actionable message. It does not hang.
- The published API carries three name types rather than three strings, and their asymmetry is
  documented where it will be read: in their docblocks.
- The handler side remains unavailable, and no part of this change pretends otherwise.
- If Temporal ever validates service and operation names, the pinned probes go red and the value
  objects can be relaxed to match — the direction this codebase prefers, since the strictness is
  ours and its justification is written down.
