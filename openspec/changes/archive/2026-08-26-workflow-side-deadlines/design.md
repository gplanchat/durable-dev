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
- **Probed after the fact, and it held:** the signal-after-deadline case now runs against a real
  server (`tests/integration/Temporal/WorkflowDeadlineTest.php`). The signal is sent only once
  `TIMER_FIRED` is visible in history, every workflow task replays from the start, and the verdict
  stayed `timeout` across those replays.

- **Answered from the code, not the server (task 1.2):** cancelling an in-flight activity
  **detaches** the workflow, it does not stop the attempt. `TemporalWorkflowCommandBuffer::cancelActivity()`
  emits `RequestCancelActivityTask` — a *request* — and `ActivityCancellationType` already encodes
  the try-cancel / wait-for-cancellation-completed distinction. The docblock and the user
  documentation say exactly that: the completion no longer resumes the workflow, and nothing more.

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

**Verdict (task 1.3): option 1.** Both backends expose the ordering task 1.1 asked about — the
in-memory backend reads one ordered stream, and Temporal's history is totally ordered by `eventId`,
which `TemporalExecutionHistory` now records for both `TIMER_FIRED` and
`WORKFLOW_EXECUTION_SIGNALED`. No new event, and the two backends stay symmetric.

Two consequences that were not visible when this was written:

- **The order that counts is the journal's, not the wall clock's.** A signal that reached the
  server before the deadline but was recorded after `TIMER_FIRED` is late. That is the price of a
  verdict that survives replay, and DUR032 says so out loud before someone files it as a bug.
- **The two backends did not agree on what a signal slot means.** In-memory counted slots over
  *all* signals and nulled out on a name mismatch; Temporal counted only signals of the matching
  name. The slot-release rule is stated in terms of "the Nth un-consumed signal of this name", so
  the in-memory backend was aligned onto the per-name semantics. Without that, "backends agree on
  the verdict" would have been false the moment two signal names met in one history.

The same rule protects the third scenario in the spec: the late signal is not consumed by the wait
that timed out, so a subsequent wait for the same name still observes it — provided the abandoned
wait **releases its slot**, which it does.

One more trap found while implementing: reading the verdict from `any()`'s return value is the same
mistake in a different costume. `AnyAwaitable::getResult()` returns the first branch **declared**
that has settled, and a replayed history can hold two settled branches at once — a signal recorded
before the deadline fired settles the wait *and* the timer, with no cancellation to separate them.
The branches are therefore read back explicitly after the race, in order: settled work, then a
fired deadline, then the work's own failure.

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
