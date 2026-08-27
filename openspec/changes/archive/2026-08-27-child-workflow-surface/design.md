# Design

## What was probed, and what was assumed

**Observed, by counting callers.** Seven call sites use the two verbs, and the split matters:

| Where | Calls | On what |
|---|---|---|
| `symfony/src/Durable/Workflow/ParallelChildEchoWorkflow` | 2 | `WorkflowEnvironment::scheduleChildWorkflow()` |
| `tests/unit/Durable/SyncChildWorkflowTest` | 4 | `WorkflowEnvironment::executeChildWorkflow()` |
| `tests/unit/Durable/Testing/HarnessParityTest` | 2 | both |
| `tests/integration/Temporal/Fixtures/IntegrationWorkflows` | 1 | `executeChildWorkflow()` |
| `tests/unit/Bridge/Temporal/Worker/TemporalChildWorkflowTest` | 2 | **`ExecutionContext`**, not the environment — untouched |
| `tests/unit/Bridge/Temporal/Worker/TemporalWorkflowCommandBufferSchedulingTest` | 2 | **the command buffer** — untouched |

Four of the eleven matches are on engine-side objects a workflow never receives. They stay, exactly
as `ExecutionContext::activity()` stayed.

**Observed, by reading.** `ChildWorkflowStub::__call()` ends with
`$this->environment->executeChildWorkflow(...)`, which is `await(schedule(...))`. The stub therefore
returns the child's result rather than an awaitable.

**Nothing was probed against a Temporal server**, and nothing here needs to be: no command, no wire
field and no history rule changes. The commands a child produces are emitted by the command buffer,
which this change does not touch. The integration suite still has to pass — it is what proves that
claim rather than asserting it.

## The defect is not the string form, it is the waiting

The previous change had one argument: two ways to do a thing means the shorter one gets taught.
Here there is a second, and it is sharper.

`ActivityStub::__call()` returns an `Awaitable`. `ChildWorkflowStub::__call()` returns the result.
The asymmetry is invisible until you try to compose, and then the typed form simply cannot express
what you want. The sample application shows exactly that: `ParallelChildEchoWorkflow` runs two
children in parallel and uses `scheduleChildWorkflow()` with a string constant, because the stub
would have awaited the first child before starting the second.

So the typed form existed, and the one case that most needed it was the one case it could not serve.

DUR033 already decided this — `await()` is the only method that waits — but it enumerated the
environment's methods. The stub was out of its field of view.

## Consequences of returning an Awaitable

This is the part that deserves care. Removing a method breaks compilation; **changing what a method
returns does not.** `$stub->run($text)` keeps compiling and starts returning an `Awaitable` where it
used to return a string.

PHPStan catches it wherever the return value is used in a typed position, which is why the static
analysis run is not a formality here. The two sample call sites return it directly from a method
declared `: string`, so they fail loudly. A call site that passed the result into `mixed` would not.

Both known call sites are in this repository. The external consumer found during
`workflow-authoring-surface` uses neither verb nor the child stub.

## Rejected

- **A second, composable accessor on the stub** — `$stub->schedule()->run(...)` beside
  `$stub->run(...)`. It keeps both behaviours and therefore keeps the asymmetry, with a longer name
  for the composable one. That is the shape DUR033 rejected when it deleted `race()` and
  `parallel()`: four names for three behaviours.
- **Keeping `executeChildWorkflow()` as a convenience.** It is `await(schedule(...))`, which the
  caller can write, and its existence is what let the stub wait in the first place.
- **Doing this inside `workflow-authoring-surface`.** Two breaking changes in one review, and this
  one carries a silent-return-type change that deserves its own attention.
