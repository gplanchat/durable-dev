# DUR035 — The condition is the primitive, and handlers are dispatched by the engine

## Status

Accepted

## Context

A workflow could only wait for things it named one at a time. `await()` took an awaitable,
`waitSignal()` took a signal name, and there was no way to say "carry on when *this* becomes true".
Anything depending on accumulated state had nowhere to go.

That gap is what made the signal surface incomplete rather than merely verbose. `#[SignalMethod]`
and `#[UpdateMethod]` were declared and read by **nothing** — a grep over `src/`, `tests/` and
`symfony/` returned their own declaration and no other use, while `#[QueryMethod]` was wired. A
handler that *pushes* — the engine calls it when a message arrives, it mutates workflow state — had
no way to wake the workflow body. And `waitUpdate()` could read an update result the server had
already recorded, but nothing produced one: the worker did not handle updates at all.

There was also a cost already being paid for the missing primitive. Because a signal wait read
history directly, it needed a positional slot, a per-name consumption counter, a rule for a wait
that gave up without consuming anything, and an order-aware history lookup so that a signal
recorded after a deadline fired could not settle the wait that deadline bounded (DUR032). The
in-memory and Temporal backends had to be aligned on what a slot even means — they disagreed. That
is a great deal of machinery for "resume when the state I care about changes", expressed once, at
the wrong altitude.

## Decision

### `await()` takes a condition. There is no second wait method.

`await()` is already the single wait of this component: DUR033 made every assembler return an
awaitable precisely so there would be one. Adding `awaitCondition()` would re-split the surface
that decision unified. A condition is therefore a second accepted argument type, not a second
method:

```php
$env->await(fn(): bool => $this->approved, Duration::hours(1));
```

The wrapper is three lines, because the awaitable contract already **is** a condition:

```php
public function isSettled(): bool { return (bool) ($this->predicate)(); }
public function getResult(): mixed { return null; }
```

`isSettled()` *is* the predicate. That is what lets a condition enter the existing deadline path
without forking it — the bounded await reads nothing else from its branches.

### A signal wait and a condition are the same mechanism, one altitude apart

Once a handler owns message ingestion, "wait for the Nth signal named X" *is* "wait until the
buffer for X holds more than K entries", where the handler fills the buffer and K is the
consumption counter. That reduction is not an aesthetic preference; it is forced by the dispatch
rule. If the handler runs first and its call is what resolves the wait, the wait no longer reads
history — it reads what the handler deposited. Keeping `waitSignal()` on a second, independent path
into the same journaled message would leave "who consumed it" without a stable answer, and the two
paths free to disagree on replay.

So `waitSignal()` and `waitUpdate()` are **removed**, not duplicated. The counter becomes
`$this->approvals[] = $payload` and `array_shift()`: owned by the workflow, obviously correct, and
not an engine rule.

### Evaluation is interleaved with message application, one message at a time

This is the decision the change turns on, and the one that could have broken DUR032 silently.

DUR032's rule — a signal recorded after the deadline fired does not settle the wait that deadline
bounded — was a *history query*. A predicate cannot be asked that question: there is no way to
interrogate a closure for "did you become true before journal position P".

A **journal position** is the rank of an event in the recorded history of one execution: the stream
index in memory and on DBAL, the `eventId` on Temporal. Positions are comparable **within one
execution's own history and nowhere else**; they are never serialized and never compared across
backends.

At a wait, **P** is the position of the deadline timer's completion (or infinity), and the wait
applies recorded messages one at a time while the next one lies below P, re-testing the predicate
after each. **Q**, the position of the message that satisfied it, is the verdict. Both P and Q must
be *stream* positions: counting Q as "how many messages have been applied" compares unlike things
and gets the ordering wrong as soon as a timer completion falls between two messages.

The deadline therefore **bounds the application of messages** rather than filtering a history
lookup, and DUR032's guarantee follows without being restated: a message recorded after the firing
is simply never applied to the wait that firing settled, and stays available to the next one.

### The wait drives the cursor, never `isSettled()`

A composite's `isSettled()` returns true as soon as *any* member is, and `ExecutionRuntime::await()`
short-circuits on it. On a replay where the timer's completion is in history, the composite would
report settled before anything had advanced the cursor: the condition would never be evaluated and
the deadline would win every time.

The loop therefore runs **before** the composite reaches the runtime, and `isSettled()` on a
condition stays a pure evaluation of the predicate at the current state.

### Handlers are declarable both ways, and the imperative form is load-bearing

`registerQueryHandler()` already existed, with `#[QueryMethod]` as the declarative form the loader
turns into that call. Signals and updates follow the same shape, with `onSignal()` and `onUpdate()`.

This is not a concession to convenience: nearly every workflow in this component's test suite is a
closure, and a closure cannot carry an attribute. Without imperative registration the primitive
would not be testable in the style the suite is written in.

### An update answers; a signal does not

That is the whole difference, and it is why updates are a separate concern rather than a parameter.
An update handler's return value is the response the caller is blocking on.

A **nullable failure field** on `WorkflowUpdateHandled` carries a failed update, rather than a
sibling event. This diverges from the house shape — `ActivityFailed` is the sibling of
`ActivityCompleted`, `ChildWorkflowFailed` of `ChildWorkflowCompleted` — and the reason is in the
protocol: Temporal writes a single `WORKFLOW_EXECUTION_UPDATE_COMPLETED` whose `Outcome` is either
a success or a failure. A sibling would make the in-memory journal diverge from what the server
actually records.

The handler **re-runs on replay**, like a signal handler: it mutates workflow state, and not
calling it again would rebuild a false state. What is frozen is the recorded outcome — the one the
caller already received.

### A message with no journal position yet is applied at its anchor

Probed against a running server: an update does not reach a worker through history. It arrives
beside it, as a `temporal.api.protocol.v1.Message` on the task, anchored on an `event_id` and a
`command_index` rather than carrying a position of its own. The worker accepts *and* answers on
that same task.

This does not weaken the positional rule, because of where determinism matters. Accepting an update
writes the original request into history, so from the next replay onward it is journal-positioned
like any signal. Only the first execution sees it out of band, and there the anchor supplies the
order. The rule reads: **a message is applied at its recorded position when it has one, and at its
anchor when it does not yet.** A replay only ever meets the first case.

## Consequences

- **Deletions, which were the point.** The signal wait slot index, the update wait slot index, the
  per-name counter, `releaseSignalWaitSlot()`, `findSignalForSlot()` and `findUpdateForSlot()` from
  the port and its implementations — and with them the disagreement between backends over what a
  slot means, which DUR032 had to align by hand. Net −273 / +169 lines on that step alone.
- **The port gains `messageAt()` and `timerCompletionPosition()`**, both in journal positions. Two
  implementations serve three backends: DBAL reads through `EventStoreHistorySource` over its own
  `EventStoreInterface`, and its `ORDER BY id ASC` supplies the stable rank the design needs.
- **A handler for a message recorded after a deadline runs only when the workflow reaches its next
  wait** — after the expiry path has already run. That is surprising, and it is DUR032's "the late
  signal remains available to a later wait" seen from the handler side.
- **A failed update replayed does not fail the execution.** Its handler runs again and raises
  again; the failure has already reached the caller, so it is absorbed on replay. Letting it
  through killed an execution the original run had left alive — a defect only a real server
  exposed.
- **On the Temporal worker, protocol commands precede the workflow's**, and every protocol message
  carries its own command. `CompleteWorkflowExecution` must end the sequence, and an update that
  unblocks a condition causes exactly that completion on the same task; a `Response` left out of
  the command sequence is not delivered, and the caller is told the workflow completed first.
- **No non-determinism detection is promised.** A condition must be a function of workflow state
  alone, and anything a replay cannot reproduce is contained with `sideEffect()` — the mechanism
  this component already sanctions. Detecting a rogue condition would require recording a verdict
  per wait, the event this change exists to avoid.
- **Migration is a documented rewrite, not a deprecation period.** Every workflow that waited for a
  signal declares a handler and awaits a condition over what it records.

## Alternatives considered

- **`awaitCondition()` as a second method.** Re-splits the surface DUR033 unified, for no gain: the
  deadline path already accepts any awaitable, and a condition is one.
- **Keeping `waitSignal()` as sugar over an auto-registered buffering handler.** Preserves
  compatibility and deletes most of the machinery, but the moment a *declared* handler exists for
  the same name, "who consumes it" is back — two paths again, which is the thing being removed.
- **Journaled abandonment, or a `WorkflowUpdateRequested` event.** Explicit in history, at the cost
  of an event and its handling in every backend, for a rule the existing ordering already supports.
- **Gating every awaitable on the cursor**, so an activity or a timer settles only at or before it.
  The uniform model, and it rewrites slot resolution for every operation type to buy what comparing
  two positions already gives.
- **Batching message application, then running the body.** Correct on a first execution and wrong
  on replay: with a deadline fired at P and a message recorded at Q > P, the replay applies both,
  the predicate sees the message, and the condition wins a race the deadline had already won.

## Related decisions

- **DUR003** — replay model and awaitables.
- **DUR011** — failure classification.
- **DUR018** — event parity, replay, and slots.
- **DUR032** — workflow-side deadlines: the rule this change re-founds.
- **DUR033** — awaitable assemblers and the single wait, which made the contract a predicate.
- **DUR034** — signal names as backed enums, which handlers take unchanged.
