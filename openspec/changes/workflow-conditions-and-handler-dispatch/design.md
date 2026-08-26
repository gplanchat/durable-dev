## Context

Two things are missing, and they turn out to be one thing.

A handler that pushes — the engine calls it, it mutates workflow state — cannot wake the workflow
body, because nothing waits on a predicate. And a wait on a predicate has no compelling use until
something pushes state into the workflow between wakes. Neither half is worth building alone.

`#[SignalMethod]` and `#[UpdateMethod]` exist as attributes and are read by nothing; only
`#[QueryMethod]` is wired, through `WorkflowDefinitionLoader::registerQueryHandlers()`. There is no
dispatch to modify — there is dispatch to build.

## What was probed, and what was assumed

Per the house rule, the boundary between observed and assumed:

- **Observed in the code, not the server:** the Temporal worker does not handle updates at all.
  `WorkflowTaskProcessor` says signal/query/update handling "will be added in the
  signal-query-update phase". `TemporalExecutionHistory` reads `WORKFLOW_EXECUTION_UPDATE_ACCEPTED`
  and `..._UPDATE_COMPLETED`, and `WorkflowClient` can send `UpdateWorkflowExecution` — so an update
  can be sent and its recorded result read back, but nothing produces one.
- **Observed by reading the current code, and it is what makes this change cheap:** the `Awaitable`
  contract is now exactly `isSettled()` and `getResult()`, and `awaitUnderDeadline()` calls nothing
  else on its branches. A condition therefore *is* an awaitable — `isSettled()` is the predicate —
  and the deadline path does not fork.
- **Not probed, and it blocks the update half (task 1):** how a worker accepts and completes an
  update against a real server — which protocol messages carry the acceptance and the response, and
  on which task they must be returned. Nothing about update responses reaches the domain before
  that is seen on `:7233`. The signal half has no such dependency: it rides the
  `WORKFLOW_EXECUTION_SIGNALED` events already read and already exercised by the integration suite.
- **Assumed, and cheap to check first:** that a Temporal workflow task can carry several journaled
  messages at once, so interleaving is a real question inside one task and not only across tasks.

## Decisions

### `await()` takes a condition. There is no second wait method.

`await()` is already the single wait of this component — `2cec7a4` made every assembler return an
awaitable precisely so there would be one. Adding `awaitCondition()` would re-split the surface that
commit unified.

So a condition is a second accepted argument type, not a second method:

```php
$env->await(fn(): bool => $this->approved, Duration::hours(1));
```

This is also the sharpest answer to "aren't a signal wait and a condition two versions of the same
mechanism?" — they are not two altitudes of a mechanism, they are one method with one more accepted
argument type.

The wrapper is three lines, because the awaitable contract already is a condition:

```php
public function isSettled(): bool { return ($this->predicate)(); }
public function getResult(): mixed { return null; }
```

### `waitSignal()` and `waitUpdate()` are removed, and that is the point

A signal wait reads history directly. That is what forced all of it: a positional slot, a per-name
consumption counter, `releaseSignalWaitSlot()` for a wait that gave up without consuming, an
order-aware history lookup so a signal recorded after a deadline fired cannot settle the wait it
bounded, and an alignment between the two backends on what a slot means — they disagreed.

Under handler dispatch none of that has anywhere to live. The handler deposits into workflow state;
the body observes that state through a condition. The counter becomes `$this->approvals[] =
$payload` and `array_shift()`, owned by the workflow, obviously correct, and not an engine rule.

Rejected: keeping `waitSignal()` as sugar over an auto-registered buffering handler. It preserves
compatibility and deletes most of the machinery, but the moment a *declared* handler exists for the
same name, "who consumes it" is back — two paths again, which is the thing being removed.

### Evaluation is interleaved with message application, not batched

This is the decision the change turns on, and the one that can break DUR032 silently.

DUR032's rule — a signal recorded after the deadline fired does not settle the wait that deadline
bounded — is a *history query* today. A predicate cannot be asked that: there is no way to
interrogate a closure for "did you become true before journal position P".

The naive implementation is "apply every journaled message, then run the body". It is correct on a
first execution and wrong on replay: with a deadline fired at position P and a signal recorded at
Q > P, replaying applies both, the body reaches its condition, the predicate sees the signal, and
the condition wins a race the deadline had already won. The verdict flips.

So the driver applies journaled messages **one at a time** and re-tests pending conditions after
each. The workflow body blocks on the condition; the driver applies the next message, dispatches
its handler, re-tests, and resumes only if it now holds. The verdict is then the position at which
it first held, and DUR032's rule follows without being restated.

That loop is the real work of this change. The primitive is three lines.

### Handlers are declarable both ways, because the tests are closures

`registerQueryHandler()` already exists on `WorkflowEnvironment`, and `#[QueryMethod]` is the
declarative form the loader turns into that call. Signals and updates follow the same pattern —
this is not a concession, it is load-bearing: nearly every workflow in the test suite is a closure,
and a closure cannot carry an attribute. Without imperative registration the new primitive is not
testable in the style the suite is written in.

### An update answers; a signal does not

That is the whole difference, and it is why updates are a separate requirement rather than a
parameter. An update handler's return value is the response the caller is blocking on, so it must
survive replay: a replay reproduces the recorded response rather than recomputing it.

## Non-goals

- **Update validator methods.** Temporal separates validation from execution for updates; that is
  its own reading and its own protocol phase. Adding it later costs an attribute argument.
- **`#[QueryMethod]`.** Already wired, and untouched.
- **Changing how signals are named.** The `BackedEnum|string` widening already landed; handlers take
  the same names.
- **A compatibility shim for `waitSignal()`.** The migration is a documented rewrite, not a
  deprecation period.

## Risks

- **Silently breaking DUR032.** Interleaving is the whole mitigation, and the regression gate is
  concrete: the deadline tests must be *rewritten* onto conditions and must still assert the same
  outcomes. Where a deadline test loses its assertion instead of changing shape, the guarantee was
  lost with the method.
- **A public API is removed.** `waitSignal()` is documented, used in the Symfony samples and the
  integration fixtures, and has just received deadlines and enum names. Part of what landed this
  week is deleted on purpose; the migration snippet has to be good enough that no one has to
  reconstruct it.
- **A predicate re-run after every applied message is user code in a hot path.** A workflow with
  many pending conditions and a long history pays linearly. Worth measuring before it is documented
  as free.
- **The update half may not be buildable as specified** until the worker-side protocol is probed. If
  the probe in task 1 contradicts the spec, the spec moves — not the server.
