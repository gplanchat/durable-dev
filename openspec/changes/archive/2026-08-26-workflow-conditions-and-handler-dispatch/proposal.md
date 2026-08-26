## Why

A workflow can only wait for things it names one at a time. `await()` takes an awaitable,
`waitSignal()` takes a signal name — there is no way to say "carry on when *this* becomes true".
Anything that depends on accumulated state has nowhere to go.

That gap is what makes the signal surface incomplete rather than merely verbose:

- `#[SignalMethod]` and `#[UpdateMethod]` are declared and read by **nothing**. A grep over
  `src/`, `tests/` and `symfony/` returns their own declaration and no other use. Only
  `#[QueryMethod]` is wired. The attributes describe a mechanism that does not exist.
- A handler that *pushes* — the engine calls it when a signal arrives, it mutates workflow state —
  has no way to wake the workflow body.
- `waitUpdate()` can read an update result the server already recorded, but nothing produces one:
  `WorkflowTaskProcessor` states that signal/query/update handling on the worker "will be added in
  the signal-query-update phase". An update is a write-only surface today.

And there is a cost already being paid for the missing primitive. Because a signal wait reads
history directly, it needs a positional slot, a per-name consumption counter, a rule for a wait
that gave up without consuming anything, and an order-aware history lookup so that a signal
recorded after a deadline fired cannot settle the wait that deadline bounded. The two backends had
to be aligned on what a slot even means. That is a lot of machinery for "resume when the state I
care about changes" — expressed once, at the wrong altitude.

## What Changes

- **`await()` SHALL accept a condition**, not a second method. A condition is a predicate over
  workflow state; the workflow resumes when it holds. `await()` is already the single wait in this
  component, and a deadline already composes with it.
- A method marked `#[SignalMethod]` or `#[UpdateMethod]` SHALL be **invoked by the engine** when
  its message is delivered, in the order the journal records.
- Handlers SHALL be declared by attribute only. Imperative registration was proposed here so that
  a closure could declare one, because the test harness runs closures — that gap is closed by
  `workflow-authoring-surface`, which gives the harness a class-based run. This change **depends**
  on it landing first.
- Journaled messages SHALL be applied **one at a time**, with pending conditions re-evaluated
  after each, so that a verdict reached at a given journal position cannot be reversed by state
  deposited after it.
- An update handler's **return value** SHALL be the update response. A signal handler has none —
  that is the whole difference between the two.
- **BREAKING**: `waitSignal()` and `waitUpdate()` are **removed**. Both are the same mechanism at a
  lower altitude, and keeping either would leave two ingestion paths for one journaled message,
  free to disagree on replay. A signal is received by a handler and observed by a condition.

## Capabilities

### New Capabilities

- `workflow-conditions`: awaiting a condition over workflow state — when it is evaluated, what
  makes the verdict reproducible on replay, and what happens to a condition that can never hold.
- `workflow-handler-dispatch`: signal and update handlers invoked by the engine — how a handler is
  declared, dispatch order, the interleaving that keeps a condition's verdict positional, and the
  response semantics that separate an update from a signal.

### Modified Capabilities

- `workflow-deadlines`: the requirement covering a signal wait under a deadline is removed with the
  method it describes. Bounding a wait in time is unchanged — it now bounds a condition.

## Impact

- **Depends on `workflow-side-deadlines` landing and being archived first**, so there is a published
  `workflow-deadlines` spec for this change's delta to remove a requirement from.
- **Deletions, which are the point of this change**: the signal wait slot index, the per-name
  consumption counter, `releaseSignalWaitSlot()`, the deadline-aware argument on
  `findSignalForSlot()` — likely the whole method — and with it the in-memory / Temporal
  disagreement over what a signal slot means. The rule those pieces enforced survives as a
  consequence of positional condition evaluation, not as its own mechanism.
- **Domain** (`src/Durable`): a condition accepted by `await()`, handler dispatch interleaved with
  the application of journaled messages, and the removal of the two wait methods.
- **Ordering**: this change depends on `workflow-authoring-surface`, which gives the test harness a
  class-based run. Without it, handlers declared by attribute are not testable in a suite written
  in closures — which is why imperative registration was proposed here, and why dropping it is safe
  only once that lands.
- **Backends**: the in-memory backend already applies its journal in order. The Temporal worker
  does **not** handle updates at all today; that work is part of this change and is the only part
  with a server surface to probe.
- **Migration**: `$env->waitSignal(X::Approve)` becomes a handler that records what arrives and an
  `await()` on a condition over it. The user documentation must carry that rewrite, because it is
  the shape every existing workflow has to adopt.
- **ADR**: DUR033 records why the condition is the primitive rather than a second wait method, why
  evaluation is interleaved with message application, and what that let us delete.
- **Dependencies**: none.
