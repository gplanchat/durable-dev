## Why

A workflow can only wait for things it names one at a time. `waitSignal()` takes a signal name,
`await()` takes an awaitable — there is no way to say "carry on when *this* becomes true". Every
state machine expressible in a durable workflow is therefore written as a sequence of named waits,
and anything that depends on accumulated state has nowhere to go.

That gap is what makes the signal surface incomplete rather than merely verbose:

- `#[SignalMethod]` and `#[UpdateMethod]` are declared and read by **nothing**. A grep over
  `src/`, `tests/` and `symfony/` returns their own declaration and no other use. Only
  `#[QueryMethod]` is wired. The attributes describe a mechanism that does not exist.
- A handler that *pushes* — the engine calls it when a signal arrives, it mutates workflow state —
  has no way to wake the workflow body. The canonical shape (`onApprove()` sets a flag, the body
  resumes when the flag is set) needs an await on a condition, and there is none.
- `waitUpdate()` can read an update result the server has already recorded, but nothing produces
  one: `WorkflowTaskProcessor` states that signal/query/update handling on the worker "will be
  added in the signal-query-update phase". An update is currently a write-only surface.

There is a second reason, and it is the one that makes this a change rather than two helpers. A
condition and a signal wait are **the same mechanism at two altitudes**: once a handler owns
signal ingestion, "wait for the Nth signal named X" *is* "wait until the buffer for X holds more
than K entries". Keeping two independent ingestion paths for the same journaled event leaves no
stable answer to "who consumed it". Founding one on the other does.

## What Changes

- A workflow SHALL be able to await a **condition** over its own state, and resume when that
  condition becomes true.
- A method marked `#[SignalMethod]` SHALL be **invoked by the engine** when the signal it names is
  delivered, in the order the journal records.
- A handler SHALL run **before** the wait it feeds resolves, and a per-name counter SHALL let the
  same signal be delivered and consumed repeatedly.
- `waitSignal()` SHALL be re-founded on that mechanism rather than reading history directly, and
  SHALL keep every behaviour it has today — the deadline verdict included.
- A method marked `#[UpdateMethod]` SHALL be invoked the same way, and its **return value** SHALL
  be the update response.
- Condition evaluation SHALL be staged by **journal position**, so that a verdict already reached
  at a given position cannot be reversed by state deposited after it.
- **BREAKING** none intended. Handlers are opt-in: a workflow that declares none behaves exactly
  as it does today.

## Capabilities

### New Capabilities

- `workflow-conditions`: awaiting a condition over workflow state — when it is evaluated, what
  makes the verdict reproducible on replay, and what happens to a condition that can never become
  true.
- `workflow-handler-dispatch`: signal and update handlers invoked by the engine — dispatch order,
  the relationship with an explicit wait, repeated delivery of the same signal, and the response
  semantics that separate an update from a signal.

### Modified Capabilities

<!-- None yet: `workflow-deadlines` is still an active change, not a published spec. See Impact. -->

## Impact

- **Depends on `workflow-side-deadlines` landing and being archived first.** Its requirements
  become the regression gate for re-founding `waitSignal()`: if the eleven deadline tests and the
  signal-after-deadline integration test stay green without edits, the reduction is safe. Until
  that change is archived there is no published `workflow-deadlines` spec to declare as modified,
  and the delta would have nowhere to point.
- **Also blocked on the in-flight awaitable refactor** (composite/quorum awaitables) being
  committed: `WorkflowEnvironment` is the single entry point both touch.
- **Domain** (`src/Durable`): an await on a condition, a condition evaluation staged by journal
  position, dispatch of `#[SignalMethod]` and `#[UpdateMethod]`, and the per-name consumption
  counter moving from an index into history to an index into what handlers deposited.
- **Backends**: the in-memory backend already applies its journal in order. The Temporal worker
  does **not** respond to updates at all today — that work is part of this change, and it is the
  only part with a server surface to probe.
- **DUR032 is re-expressed, not repealed.** "A signal recorded after the deadline fired does not
  settle the wait it bounded" is a history query today. On a condition it becomes "a condition that
  timed out at journal position P is not settled by state deposited after P". Same rule, different
  home, and the place this change can silently break.
- **User documentation**: signals gain their handler form, and the difference between a handler and
  an explicit wait must be stated rather than left to taste.
- **ADR**: DUR033 records why the condition is the primitive and the named wait its special case,
  and why evaluation is staged by journal position.
- **Dependencies**: none.
