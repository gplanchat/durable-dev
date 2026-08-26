## Context

`WorkflowEnvironment` already carries every primitive a deadline needs: `timer()` produces an
awaitable like any other, `any()` settles on the first branch, and the composite it builds cancels
the losing branches best-effort. What is missing is not machinery — it is **identity**: `any()`
hands back the winning value and drops the knowledge of which branch produced it.

## What was probed, and what was assumed

Per the house rule, the boundary between observed and assumed:

- **Nothing new was probed against the server, and nothing new is encoded.** A deadline is a timer,
  and timer semantics were established when timers landed. This change adds no invariant about
  server behaviour.
- **Assumed, and worth probing before task 4:** that cancelling an in-flight activity after a
  deadline actually stops the attempt server-side rather than merely detaching the workflow from
  it. The existing code documents loser cancellation as *best effort*. If the server keeps running
  the attempt, the deadline still holds from the workflow's point of view — the spec only requires
  that a late completion does not resume the execution — but the docblock must say which of the two
  it is, and the user documentation must not promise more.

## Decisions

### A timeout is a failure, not a null

The whole point of the change is that a caller cannot distinguish an elapsed deadline from a
returned value. Returning `null` on timeout would rebuild that ambiguity one layer up: `null` is a
value the awaited work can legitimately produce.

A dedicated failure also gives the shape callers already write for compensation — a `catch` next to
the activity-failure catch, not an `if` on a sentinel.

Rejected: returning a small result object carrying `timedOut(): bool`. It is honest, but it forces
every call site to unwrap even when no deadline can elapse on the happy path, and it does not
compose with the existing failure handling.

### The verdict is read from history, never from a branch identity computed at runtime

The naive helper is four lines: race the work against a timer, then ask which one settled. That is
correct for a first execution and wrong on replay, and the failure is silent.

A signal wait consumes a positional slot and resolves from the first matching signal recorded for
that slot. If the deadline elapses and the signal is delivered afterwards, the recorded history
contains both — and a replay resolving the slot from the signal reaches the opposite verdict from
the original run, at which point the workflow takes a different path than the one already
journaled.

So the verdict SHALL be a function of **history order**: a signal recorded after the deadline timer
fired does not settle the wait that timer bounded. Two ways to get there:

1. **Order-aware lookup** — the wait knows its deadline, and only accepts an event recorded before
   the deadline fired. No new event type; the rule lives in the history source.
2. **Journaled abandonment** — the timed-out wait records that it gave up, and the slot is closed
   from then on. Explicit in history, at the cost of a new event and its handling in both backends.

Option 1 is preferred: it adds no event, and it keeps the two backends symmetric because both
already order their history. Option 2 is the fallback if a backend turns out not to expose a usable
ordering between a fired timer and a delivered signal — which is exactly what task 1 checks.

The same rule protects the third scenario in the spec: the late signal is not consumed by the wait
that timed out, so a subsequent wait for the same name still observes it. That is a consequence of
the rule, not an extra mechanism.

### The deadline stays a workflow-side notion

Activity bounds (`ActivityTimeouts`) are a different concept and stay untouched: they are enforced
by the backend, and they survive a worker crash. A workflow-side deadline bounds *this* wait, in
*this* execution, and covers what activity bounds cannot — a child workflow, a signal, an update, a
composed group.

Documentation must keep the two apart. Bounding a single activity attempt is `ActivityTimeouts`;
bounding anything else is a deadline.

## Non-goals

- Giving `any()` branch identity in general. That is a wider change to the awaitable contract, and
  the deadline case is served without it because the helper holds the timer it created.
- A deadline on `waitUpdate()`. Same shape, but updates carry response semantics that deserve their
  own reading; adding it later costs one parameter.
- Cancelling the whole workflow on a deadline. A deadline bounds a wait, not an execution;
  execution-level bounds already exist.

## Risks

- **Silent replay divergence** is the risk this change exists to remove, and it is also the one it
  can reintroduce if the verdict is computed anywhere but from history. Every test in task 2 that
  covers a verdict must run the replay path, not only the first execution.
- **Best-effort cancellation** means a bounded activity may still be running after the deadline.
  The spec requires only that its completion does not resume the workflow. Documentation must not
  promise the attempt stops.
