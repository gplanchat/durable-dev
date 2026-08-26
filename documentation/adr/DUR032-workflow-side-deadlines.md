# DUR032 — Workflow-side deadlines: a failure, and a verdict read from history

## Status

Accepted

## Context

Bounding a wait in time was possible only by composing a race by hand, which is what the user
documentation taught:

```php
$winner = $env->any(
    $activities->callProvider($orderId),
    $env->timer(Duration::seconds(30)),
);
```

This is not merely verbose, it is ambiguous. `any()` returns the winning **value** and drops the
knowledge of which branch produced it, and a timer resolves to `null`. The moment the awaited work
can legitimately return `null` or `false`, the workflow cannot tell "the provider answered nothing"
from "the deadline elapsed". A saga that compensates on timeout compensates on a legitimate empty
answer too.

`waitSignal()` took no deadline at all, although "wait for approval, give up after an hour" is the
canonical saga shape. And a hand-written race over a signal wait was **not replay-safe**: a signal
wait consumes a positional slot, so a signal delivered after the deadline elapsed was found by the
next replay, resolved the slot, and the replayed execution reached the opposite verdict from the
one already journaled — silently taking a path the journal contradicts.

## Decision

### A deadline is an optional argument, not a new vocabulary

`await()` and `waitSignal()` take an optional second argument. Without it, both behave exactly as
before. With it, the wait is bounded.

### An elapsed deadline is a failure, not a null

`DeadlineExceededException` carries the deadline that elapsed and what it was bounding, and it is
catchable on its own. Returning `null` on timeout would rebuild the ambiguity one layer up, since
`null` is a value the awaited work can legitimately produce. A dedicated failure also gives the
shape callers already write for compensation — a `catch` next to the activity-failure catch, not
an `if` on a sentinel. Uncaught, it is classified as its own kind
(`WorkflowExecutionFailed::KIND_DEADLINE_EXCEEDED`), which names the wait that did not conclude
rather than flattening it onto a generic handler failure.

Rejected: a small result object carrying `timedOut(): bool`. Honest, but it forces every call site
to unwrap even when no deadline can elapse on the happy path, and it does not compose with the
existing failure handling.

### The verdict is read from the branches, never from the winning value

The composite is still a `CancellingCompositeAwaitable` over an `AnyAwaitable` — that is what
`AwaitableInspector::waitsOnTimer()` traverses, and without it no wake is scheduled and the
deadline never fires. But its **return value** decides nothing. `AnyAwaitable::getResult()` returns
the first branch **declared** that has settled, and a replayed history can contain two settled
branches at once: a signal recorded before the deadline fired leaves both the wait and the timer
settled, with no cancellation to separate them. Reading the winner from declaration order would
then invent a timeout the journal never recorded.

So the branches are read back explicitly: settled work wins, then a fired deadline, then the work's
own failure — with a cancelled loser (`ActivitySupersededException`) read as "the deadline won"
and `WorkflowCancelledFailure` passed through untouched.

### A signal recorded after the deadline fired does not settle the wait it bounded

The rule lives in the history source: `findSignalForSlot()` takes the id of the deadline timer and
refuses any signal recorded after that timer fired. No new event type, and both backends already
order their history — the in-memory backend reads one ordered stream, Temporal a history totally
ordered by `eventId`.

**The order that counts is the journal's, not the wall clock's.** A signal that physically reached
the server before the deadline but was recorded after `TIMER_FIRED` is late. That is the price of a
verdict that survives replay, and it is not a bug.

A wait that gave up consumed no signal, so it **releases its slot**: the late signal remains
available to a later `waitSignal()` for the same name. Getting there also required the in-memory
backend to count signal slots per **name**, as the Temporal backend already did — the two had
disagreed on what "the Nth signal wait" meant, and the parity this change claims would have been
false.

Rejected: journaling an explicit abandonment event. Explicit in history, at the cost of a new event
and its handling in both backends, for a rule the existing ordering already supports.

### The deadline stays a workflow-side notion

`ActivityTimeouts` is a different concept and stays untouched: it is enforced by the backend and
survives a worker crash. A workflow-side deadline bounds *this* wait in *this* execution, and
covers what activity bounds cannot — a child workflow, a signal, an update, a composed group. The
documentation keeps the two apart.

## Consequences

- Losing branches are cancelled, so no orphaned activity and no dead timer wake the execution.
  Cancelling an in-flight activity remains **best effort**: `RequestCancelActivityTask` is a
  *request*, and an attempt that does not honour it keeps running on its worker
  (`ActivityCancellationType`). The guarantee is only that its completion no longer resumes the
  workflow — the documentation must not promise more.
- `WorkflowHistorySourceInterface::findSignalForSlot()` gains an optional third argument.
  Third-party implementations must adapt, as with DUR031.
- The in-memory signal slot semantics changed from "the Nth signal, whatever its name" to "the Nth
  signal of this name". This makes the two backends agree; a workflow that relied on the previous
  cross-name numbering changes behaviour.
- The deadline timer is created unconditionally, never behind an `isSettled()` early-out: an
  awaitable that is pending on the first run and settled on replay would otherwise shift the timer
  slot index between passes and misalign every later timer.

## Alternatives considered

- **Giving `any()` branch identity in general.** A wider change to the awaitable contract; the
  deadline case is served without it because the helper holds the timer it created.
- **A deadline on `waitUpdate()`.** Same shape, but updates carry response semantics that deserve
  their own reading. Adding it later costs one parameter.
- **Cancelling the whole workflow on a deadline.** A deadline bounds a wait, not an execution;
  execution-level bounds already exist.

## Related decisions

- **DUR003** — replay model and awaitables.
- **DUR018** — event parity, replay, and slots.
- **DUR011** — failure classification.
- **DUR031** — value objects across the ports.
