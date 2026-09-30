# DUR052: The resume protocol beyond activities: each pair names its own fact

## Status

Proposed — drafted by an agent for #584. `documentation/adr/` is supervised: this ADR takes effect
when the user approves its text on its pull request. The three choices recorded under "Decision"
are the user's decisions of 2026-09-28, on #584.

Follows [DUR050](DUR050-the-resume-is-dispatched-first.md), which applied its protocol to the
activity paths and left the other append-then-send pairs to a follow-up that would "apply the same
protocol". DUR050 is not amended: this ADR records why the follow-up is not the same protocol
throughout.

## Context

DUR050 made the activity worker send the resume before it journals the outcome, and again after.
A resume that arrives first names the activity whose outcome it announces, and waits for it. Its §9
left four other pairs with the same gap. Read one by one, they are not alike:

- **Updates have no gap.** `DeliverWorkflowUpdateHandler` appends nothing: the update travels
  inside the resume (`pendingUpdates`), and the pass that applies it journals it.
- **A child's outcome reported to its parent** (`ResumeWorkflowHandler::finalizeAsyncChildOnParentIfLinked()`)
  appends to the parent, removes the parent link, sends the parent's resume, and only then marks
  the child completed. A crash after the unlink leaves a redelivered child resume that finds no
  parent link: the parent's resume is never sent. A crash before the unlink appends the child's
  outcome to the parent a second time.
- **A signal cannot be named.** `DeliverWorkflowSignalMessage` carries a `requestId`, but
  `WorkflowSignalReceived` does not record it, so no resume can say which signal it announces. The
  same gap makes a redelivered signal message append the signal twice. The guide and the Symfony
  bench route signals `sync`, where nothing is ever redelivered, so sending first is the only
  protection there.
- **A timer is decided while it is appended.** `ExecutionRuntime::checkTimers()` works out which
  timers are due as it appends `TimerCompleted`. A crash after that append leaves a redelivered
  `FireWorkflowTimersMessage` that finds nothing new and sends no resume.

## Decision

1. **Updates are out of scope.** They have no append to lose.
2. **One awaited fact, one method** (user's choice 2). A small readonly value object names what a
   resume announces: a kind (`activity`, `child`, `signal`, `timer`) and its id or ids.
   `ResumeWorkflowMessage` carries it in place of DUR050's `awaitedActivityId`, and
   `WorkflowResumeDispatcher` has one method taking it in place of `dispatchResumeAnnouncing()`.
   A message serialized with `awaitedActivityId` still reads, as an activity fact.
   `ResumeWorkflowHandler` waits until the journal holds the named fact:
   - an activity: a terminal outcome for that activity id;
   - a child: that child's outcome in the parent's journal;
   - a signal: a `WorkflowSignalReceived` with that request id;
   - timers: a `TimerCompleted` or a `TimerCancelled` for each named timer id. A named timer can
     be cancelled before it fires, for instance by a signal-driven pass between a crash and the
     redelivery, and a wait that accepted only `TimerCompleted` would then never end. The
     activity wait already counts `ActivityCancelled`, for the same reason.
3. **A child reports to its parent in the protocol's order.** The resume naming the child is sent,
   the outcome is appended to the parent unless the parent already holds it, the plain resume is
   sent, and the parent link is removed last. A redelivered child resume then finds the link and
   sends the parent's resume again.
4. **A signal is journalled with its request id** (user's choice 1). `WorkflowSignalReceived` gains
   an optional `requestId`; events written before read as null. `DeliverWorkflowSignalHandler`
   skips the append when a signal with that request id is already in the journal, and sends the
   resume anyway: a redelivered signal is no longer applied twice. It sends the resume naming the
   request id first, and the plain one after.
5. **The due timers are named before they fire.** One helper lists the timers that are scheduled,
   neither completed nor cancelled, and due. `checkTimers()` and `TimerWakeDelayCalculator` stop
   computing that set separately. `FireWorkflowTimersHandler` sends the resume naming the due
   timers first, then fires them, then sends the plain resume. It never sends a resume when no
   timer is due: an early timer message on the in-memory transport would otherwise spin.
   `checkTimers()` reads the clock after the send, so it fires the named timers and possibly
   others that fell due in between; the plain resume after it covers those. It fires fewer only
   when a pass between a crash and the redelivery cancelled one (the lock serialises passes),
   which the timer fact accepts.
6. **Everything else follows DUR050**: the second send after the append, and no early send where
   the resume runs inline.

## Consequences

- **Port change.** `dispatchResumeAnnouncing(string $executionId, string $activityId)` becomes a
  method that takes the awaited fact. If DUR050's method has shipped in no release by then, its
  `UPGRADE.md` entry is amended; otherwise a second entry describes the change.
- **Journal format.** `WorkflowSignalReceived` stores a `requestId`. It is optional in both
  directions: old events read as null, and an older reader ignores the field. The event-store
  conformance suite round-trips it.
- **A signal is applied once per request id**, on the backends where Durable delivers signals
  itself. Temporal already drops a second signal with the same request id, server-side.
- **Tests.** The crash bench gains the child path, killed after the parent's append, and the timer
  handler, killed after `TimerCompleted`. Both complete. A redelivered signal is journalled once.
- **Temporal is unaffected.** The server owns delivery, and its dispatcher is a no-op.
