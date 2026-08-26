# DUR036 — A stub assembles, it does not wait

## Status

Accepted

## Context

DUR033 decided that `await()` is the only method that waits, and that everything else assembles and
returns an `Awaitable`. Its reasoning was explicit: *a method that waited on the caller's behalf
could not be composed with anything, which is the only reason to write an assembler in the first
place.*

That decision enumerated the methods of `WorkflowEnvironment`. It did not reach the stubs, and one
of them contradicted it. `ActivityStub::__call()` returned an `Awaitable`; `ChildWorkflowStub::
__call()` returned the child's **result**, already awaited, because it delegated to
`WorkflowEnvironment::executeChildWorkflow()` — itself `await(schedule(...))`.

The asymmetry is invisible until you compose. The sample application shows it precisely:
`ParallelChildEchoWorkflow` runs two children in parallel and could not use the stub, because the
stub would have awaited the first child before the second was started. It fell back to
`scheduleChildWorkflow()` with a string constant.

So the typed form existed, and the one case that most needed it was the one case it could not
serve.

## Decision

**A stub call returns an `Awaitable`.** This holds for every stub, and it is the rule DUR033 would
have written had stubs been in its field of view.

`scheduleChildWorkflow()` and `executeChildWorkflow()` leave the surface a workflow can reach. A
child is started through `childWorkflowStub()`, resolved from the child's class, and the caller
awaits — or races, or counts towards a quorum, or bounds by a deadline.

`ChildWorkflowStub` receives `ChildWorkflowSchedulerInterface` rather than the whole environment,
mirroring what DUR035 did for activities: the adapter is built by `childWorkflowStub()` and never
returned, so the string form stays inside the engine.

## Consequences

- **Breaking, on two counts.** The two verbs are gone, which fails to compile. And a stub call that
  returned the child's result now returns an `Awaitable` — which **keeps compiling** and changes
  what the expression evaluates to. That second one is why the static analysis run matters more
  here than the test run: PHPStan catches it wherever the value lands in a typed position, and only
  there.
- The sample application can finally express what it was written to demonstrate. That is the
  measure of whether this change was worth making.
- Symmetry between the two stubs is restored, so "a stub call is an awaitable" becomes a rule a
  reader can rely on rather than a property to check per stub.
- Nothing on the wire moved: the commands a child produces are emitted by the command buffer, which
  this change does not touch. Twenty integration tests against a real server back that.

## Alternatives considered

- **A second, composable accessor** — `$stub->schedule()->run(...)` beside `$stub->run(...)`. It
  keeps both behaviours, so it keeps the asymmetry and gives the composable form the longer name.
  DUR033 rejected exactly this shape when it deleted `race()` and `parallel()`: four names for
  three behaviours.
- **Keeping `executeChildWorkflow()` as a convenience.** It is `await(schedule(...))`, which the
  caller can write in one more token, and its existence is what let the stub wait in the first
  place.

## Related decisions

- **DUR033** — awaitable assemblers and the single wait. This ADR extends its rule to the stubs it
  did not reach.
- **DUR035** — the workflow authoring surface. Same narrow-port shape, applied to child workflows;
  DUR035 excluded them on purpose to keep two breaking changes out of one review.
- **DUR004** — `ActivityStub`. It was already right, which is what made the asymmetry visible.
