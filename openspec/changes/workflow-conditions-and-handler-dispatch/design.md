## Context

Two things are missing, and they turn out to be one thing.

A handler that pushes — the engine calls it, it mutates workflow state — cannot wake the workflow
body, because nothing in `WorkflowEnvironment` waits on a predicate. And a wait on a predicate has
no compelling use until something pushes state into the workflow between wakes. Neither half is
worth building alone.

`#[SignalMethod]` and `#[UpdateMethod]` exist as attributes and are read by nothing; only
`#[QueryMethod]` is wired, through `WorkflowDefinitionLoader::registerQueryHandlers()`. So there is
no dispatch to modify — there is dispatch to build.

## What was probed, and what was assumed

Per the house rule, the boundary between observed and assumed:

- **Observed in the code, not the server:** the Temporal worker does not handle updates at all.
  `WorkflowTaskProcessor` says signal/query/update handling "will be added in the
  signal-query-update phase". `TemporalExecutionHistory` reads `WORKFLOW_EXECUTION_UPDATE_ACCEPTED`
  and `..._UPDATE_COMPLETED`, and `WorkflowClient` can send `UpdateWorkflowExecution` — so an update
  can be sent and its recorded result read back, but nothing produces one. `waitUpdate()` is a
  write-only surface today.
- **Not probed, and it blocks the update half (task 1):** how a worker accepts and completes an
  update against a real server — which protocol messages carry the acceptance and the response,
  and on which task they must be returned. Nothing about update responses should reach the spec's
  promises before that is seen on `:7233`. The signal half has no such dependency: it rides the
  `WORKFLOW_EXECUTION_SIGNALED` events already read and already exercised by the integration suite.
- **Assumed, and cheap to check first:** that a Temporal workflow task can carry several journaled
  messages at once, so "handlers run in journal order" is a real ordering question inside one task
  and not only across tasks. The in-memory backend applies one event per resume today.

## Decisions

### The condition is the primitive; the named wait is its special case

`waitSignal()` and a condition are the same mechanism at two altitudes. Once a handler owns signal
ingestion, "wait for the Nth signal named X" *is* "wait until the buffer for X holds more than K
entries", where the handler fills the buffer and K is the consumption counter.

That reduction is not an aesthetic preference, it is forced by the dispatch rule: if the handler
runs first and its call is what resolves the wait, then the wait no longer reads history — it
reads what the handler deposited. Keeping `waitSignal()` on a second, independent path into the
same journaled event would leave "who consumed it" without a stable answer, and the two paths free
to disagree on replay.

So `waitSignal()` is re-founded, not duplicated. Its public behaviour does not change.

**The counter is not new.** `ExecutionContext` already carries a per-name signal consumption index,
and `releaseSignalWaitSlot()` already encodes "a wait that gave up consumed nothing" — both landed
with the deadline change. They move from indexing history to indexing what handlers deposited. The
rule they express is unchanged, which is why the existing deadline tests are the right gate.

Rejected: leaving `waitSignal()` reading history and letting handlers run beside it. It is the
smaller diff and the larger bug: a signal would be observed twice, by two paths with different
notions of order.

### Condition evaluation is staged by journal position

This is the decision the whole change turns on, and the one that can break DUR032 silently.

DUR032's rule — "a signal recorded after the deadline timer fired does not settle the wait that
timer bounded" — is a *history query* today: the wait knows its deadline timer and refuses any
signal recorded after it fired. A predicate cannot be asked that question. There is no way to
interrogate an arbitrary closure for "did you become true before journal position P".

So the evaluation loop has to make the position explicit: apply journaled inputs **one at a time,
in recorded order**, re-evaluating pending conditions after each. The verdict of a wait is then the
position at which its condition first held, and "a wait that gave up at position P is not settled
by state deposited after P" is expressible again.

Rejected: evaluating conditions "whenever state changes". It is the obvious implementation, it
works on a first execution, and it makes the deadline verdict unreconstructible — the exact
failure mode DUR032 exists to remove, reintroduced one layer down.

### A predicate is workflow state, and nothing else

A condition is arbitrary user code re-run on every replay. Reading a clock, a random source, or
anything the journal does not record makes the replay diverge from the execution it is
reconstructing. The rule is the determinism rule that already governs the workflow body (DUR003);
what this change adds is that a divergence must be **reported**, naming the condition, rather than
resolved to whichever outcome the replay happens to reach.

### An update answers; a signal does not

That is the whole difference, and it is what makes updates a separate requirement rather than a
parameter. A signal handler's return value is nothing. An update handler's return value is the
response the caller is blocking on, so it must survive replay: a replay reproduces the recorded
response rather than recomputing it.

## Non-goals

- **Update validator methods.** Temporal separates validation from execution for updates; that is
  its own reading and its own protocol phase. Adding it later costs an attribute argument.
- **A deadline on `awaitCondition()` beyond what `await()` already gives.** The spec requires that a
  condition composes with a deadline; it does not ask for a second deadline vocabulary.
- **`#[QueryMethod]`.** Already wired, and untouched.
- **Changing how signals are named.** The `BackedEnum|string` widening already landed; handlers
  take the same names.

## Risks

- **Silently breaking DUR032** is the risk this change carries, and staging by journal position is
  the whole mitigation. The regression gate is concrete: the eleven tests of `WorkflowDeadlineTest`
  and the signal-after-deadline integration test must stay green **without edits**. Edits to those
  tests are the signal that the re-founding changed observable behaviour.
- **A predicate re-run on every replay is user code in a hot path.** The evaluation loop
  re-evaluates pending conditions after each applied input; a workflow with many conditions and a
  long history pays for it linearly. Worth measuring before it is documented as free.
- **The update half may not be buildable as specified** until the worker-side protocol is probed.
  If the probe in task 1 contradicts the spec, the spec moves — not the server.
