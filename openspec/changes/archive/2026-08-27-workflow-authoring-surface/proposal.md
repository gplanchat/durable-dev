## Why

`WorkflowEnvironment` carries twenty public methods. Three of them are the engine's own plumbing,
one is unreachable dead weight, and one — `activity()` — is a primitive the library no longer
teaches but cannot currently hide.

The documentation has just stopped showing `$env->activity('charge', [...])`, because the way to
call an activity is a typed stub: `$this->orders->charge($orderId)`, resolved from a contract, so
that a typo is a type error rather than an activity that is never scheduled. But the method is
still public, still the shortest path, and still what every example a reader finds elsewhere uses.
A surface that offers two ways to do one thing teaches the wrong one by being shorter.

Three facts make this a change rather than a deletion:

- **`activity()` is underneath `activityStub()`, not beside it.** `ActivityStub` schedules by
  calling it. Removing it from the public surface requires giving the stub a route that is not the
  public API.
- **The test harness has no other shape.** `WorkflowTestEnvironment::run()` takes a callable, so a
  test workflow is a closure receiving the environment — a signature no real workflow has had since
  the environment moved to the constructor. The testing guide says so in a comment today. The
  harness offers a form the product no longer teaches, and every one of the forty-seven direct
  `activity()` calls in the suite exists because of it.
- **Three methods are the engine talking to itself.** `registerQueryHandler()` is called by the
  definition loader; `hasQueryHandler()` and `callQueryHandler()` by the Temporal task processor.
  A workflow author calling them would be bypassing `#[QueryMethod]`, and nothing stops them.

## What Changes

- Scheduling an activity from workflow code SHALL go through a typed stub. The name-and-array form
  SHALL NOT be part of the surface a workflow author can reach.
- Registering and answering queries SHALL NOT be part of that surface either: a query handler is
  declared with `#[QueryMethod]`, and the engine wires it.
- The test harness SHALL be able to run a workflow **class** — the same shape as production — so
  that a test no longer needs a form the product does not teach.
- The closure form SHALL remain available for tests that genuinely want an anonymous workflow; it
  is the harness's shape, and the guide SHALL say so rather than implying it mirrors production.
- `async()` SHALL be removed. It returns an already-settled awaitable, has no caller anywhere, and
  is described in two ADRs as scheduling asynchronous work — which it does not do.
- **BREAKING** yes. Code calling `$env->activity()`, `$env->registerQueryHandler()`,
  `$env->callQueryHandler()`, `$env->hasQueryHandler()` or `$env->async()` stops compiling. Each
  has a stated replacement, and none is reachable by accident from a well-formed workflow.

### Not in scope

- `continueAsNew()` and `executionId()` have no callers today, and they stay. They are legitimate
  things for a workflow to do — relaunch itself, name itself in a log — and removing an unexercised
  capability is not the same as removing a wrong one.
- The four remaining scheduling verbs (`scheduleChildWorkflow`, `executeChildWorkflow`,
  `childWorkflowStub`, `waitUpdate`) keep both their name-based and typed forms. Child workflows
  have a stub too, and the same argument applies to them — but it is a separate change, and mixing
  it in would make this one impossible to review.

## Capabilities

### New Capabilities

- `workflow-authoring-surface`: what a workflow author can call on the environment, what the engine
  keeps to itself, and what a test may do that production does not.

### Modified Capabilities

<!-- None: no existing documented requirement changes. -->

## Impact

- **Domain** (`src/Durable`): five public methods leave `WorkflowEnvironment`; `ActivityStub` gains
  an internal route to scheduling; `WorkflowDefinitionLoader` and the Temporal task processor reach
  query handlers without going through the workflow's own environment.
- **Testing** (`src/Durable/Testing`): `WorkflowTestEnvironment` gains a class-based run.
- **Test suite**: forty-seven direct `activity()` calls are rewritten. Most are in
  `DriverParityRegressionTest`, where they are anonymous workflows by design.
- **User documentation**: the testing guide stops teaching a shape that only the harness has.
- **ADR**: DUR037 records why the scheduling primitive is not public, and why the test harness
  needed a class-based run before it could be hidden.
- **Dependencies**: none.
