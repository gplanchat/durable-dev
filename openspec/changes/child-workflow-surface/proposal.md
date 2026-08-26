## Why

`workflow-authoring-surface` took the string-and-array form of activity scheduling off the surface a
workflow author can reach, and left the child-workflow verbs alone on purpose: the same argument
applied, and two independent breaks in one review is one too many. This is that follow-up.

It is not a copy of the previous change, because the child stub has a defect the activity stub does
not. **`ChildWorkflowStub::__call()` waits on the caller's behalf.** It returns the child's result,
already awaited, where `ActivityStub` returns an `Awaitable`.

DUR033 decided the opposite, in as many words:

> `await()` is the only method that waits. Every other method assembles and returns immediately …
> A method that waited on the caller's behalf could not be composed with anything, which is the only
> reason to write an assembler in the first place.

The stub escaped that decision because DUR033 enumerated the environment's methods, not the stub's.
The consequence is visible in the sample application: `ParallelChildEchoWorkflow` runs two children
in parallel and cannot use the stub to do it — it falls back to `scheduleChildWorkflow()` with a
string. The typed form exists and is unusable for the one case that needs composition.

`executeChildWorkflow()` has the same problem by construction: it is `await(schedule(...))`.

## What Changes

- A workflow SHALL start a child workflow through a typed stub, resolved from the child's class.
- **A stub call SHALL return an `Awaitable`**, like every other assembler, so a child can be raced,
  quorumed or bounded by a deadline. The caller awaits.
- Starting a child by naming its type as a string SHALL NOT be on the surface a workflow reaches,
  and neither SHALL a verb that waits on the caller's behalf.
- **BREAKING** yes, on two counts. `scheduleChildWorkflow()` and `executeChildWorkflow()` are
  removed. And a stub call that used to return the child's result now returns an `Awaitable`:
  `$stub->run($x)` becomes `$env->await($stub->run($x))`. The second is the one to watch — it
  changes what existing code *returns* rather than failing to compile, so the tests that cover it
  matter more than usual.

### Not in scope

- `waitUpdate()` keeps its string name. Updates carry response semantics that deserve their own
  change, as DUR032 already noted when it declined to give them a deadline.

## Capabilities

### Modified Capabilities

- `workflow-authoring-surface`: the requirement that activities are only reachable through a typed
  contract is extended to child workflows, and gains the rule that a stub call assembles rather than
  waits.

## Impact

- **Domain** (`src/Durable`): two public methods leave `WorkflowEnvironment`; `ChildWorkflowStub`
  gains the narrow scheduling port and returns an `Awaitable`.
- **Sample application**: `ParallelChildEchoWorkflow` can finally use the stub — it is the reason
  this change is worth making rather than a tidy-up.
- **Test suite**: seven call sites, five of them on `ExecutionContext` rather than the environment
  and therefore untouched.
- **User documentation**: the `WorkflowEnvironment` table and the child-workflow section.
- **ADR**: DUR036 records that the stub assembles rather than waits, and why DUR033 missed it.
- **Dependencies**: none.
