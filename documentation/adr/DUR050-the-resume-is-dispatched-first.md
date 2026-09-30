# DUR050: The resume is dispatched first, and a resume that arrives early waits

## Status

Proposed — drafted by an agent for #328. `documentation/adr/` is supervised: this ADR takes effect
when the user approves its text on its pull request. The direction (option b) and the three choices
recorded under "Decision" are the user's decisions of 2026-09-28.

Related to [DUR030](DUR030-dbal-backend-simplified-durable-execution.md), whose single-database
backend is the one the gap below bites hardest; DUR030 is not amended.

## Context

When an activity worker finishes an attempt, two things happen, in this order: the outcome is
appended to the journal, then a `ResumeWorkflowMessage` is sent so that a workflow worker replays
the execution and moves on (`ActivityMessageProcessor`). Nothing makes the two atomic, and no
sweeper looks for an execution whose last activity settled without a resume.

A crash between the two leaves exactly that: an execution that is complete in its journal and that
nothing will ever advance. The Messenger dispatcher makes it wider than it looks. Every resume
carries `DispatchAfterCurrentBusStamp`, so a resume sent from inside the activity handler is held in
memory until the handler returns. On Symfony the call order in the processor is therefore
irrelevant today: the send always happens after the append, and a crash anywhere after the append
loses it.

The same "append, then send" pair appears elsewhere: signal and update delivery
(`DeliverWorkflowSignalHandler`, `DeliverWorkflowUpdateHandler`), a child's outcome reported to its
parent (`ResumeWorkflowHandler::finalizeAsyncChildOnParentIfLinked()`), and timer wake-ups
(`FireWorkflowTimersHandler`).

Three options were considered in #328:

- (a) when the Messenger transport is Doctrine on the journal's connection, append and send in one
  transaction. Only one transport and one backend qualify, and the others keep the gap.
- (b) send the resume first, and make the resume safe to receive before the outcome it announces.
- (c) a real outbox table and a sweeper. It closes the gap everywhere, at the cost of a table, a
  poller, and a component to operate.

`durable.activity_transport.table_name` (default `durable_activity_outbox`) names an outbox that
was never built.

## Decision

**Option (b)** (the user's decision): the activity worker sends the resume **before** it appends
the outcome, and the resume contract becomes **at-least-once**.

1. **A resume names what it announces.** `ResumeWorkflowMessage` gains an optional awaited
   activity id. Resumes that announce nothing in particular (a new run, a replay that suspended
   again) leave it empty and behave as today.
2. **A resume that arrives early waits.** When the awaited outcome is not in the journal yet,
   `ResumeWorkflowHandler` concludes nothing: it throws a dedicated core exception, and the
   transport's retry is the wait. The core stays transport-neutral: Messenger and Laravel queues
   both retry a failed message with a delay.
3. **The activity path sends immediately.** Its first resume cannot carry
   `DispatchAfterCurrentBusStamp`, or the order in the processor would stay cosmetic. The other
   callers keep the stamp.
4. **A resume is sent again after the append** (user's choice 1). The early resume may exhaust its
   retries before the outcome lands (Messenger's default budget is 3 retries, about 7 seconds), for
   instance when the append sits in a `doctrine_transaction` the application added. The second send
   makes liveness independent of any retry budget, for one message more per activity; replay makes
   it harmless when the first one already did the work.
5. **A crash between the send and the append is recovered by redelivery**, on the paths that
   acknowledge the activity message after processing it: the Messenger worker consuming an
   asynchronous transport, and the Laravel queue worker. A worker killed there leaves the message
   unacknowledged. The transport redelivers it, the attempt runs again (at-least-once, as an
   activity already is under retries), and it sends and appends again. The delay is the
   transport's own: the Doctrine transport redelivers after `redeliver_timeout`, 3600 seconds by
   default.
   It does **not** hold for the inline drain: `MessengerActivityTransport::dequeue()` acknowledges
   the message before the processor runs it, so a crash there loses the attempt. The inline drain
   serves a single process that nothing outlives; this ADR does not make it durable.
6. **A resume routed `sync` keeps append-then-send** (user's choice 2). A synchronous resume runs
   inline, before the append, every time. Both hosts already refuse that routing where a journal
   outlives the process: `RequireAsyncRoutingPass` (#554) with the DBAL journal on Symfony, and
   `DurableServiceProvider` with the Illuminate journal on Laravel. It remains possible with an
   in-memory journal on either host. There the activity path detects it, the way
   `DurableWorkerInspection` does, and appends before it sends, which is correct in one process.
7. **A failed send is not a failed activity.** Today `dispatchResume()` sits inside the attempt's
   `try`, so a broker error appends `ActivityTaskFailed` *after* the attempt's `ActivityCompleted`,
   and can spend the retry budget. An error after the outcome fails the message instead, which is
   redelivered (#583).
8. **The dead node goes.** `activity_transport.table_name` is removed. Setting it becomes a
   configuration error, documented in `UPGRADE.md`.
9. **Scope: the activity paths** (completed, failed, cancelled), as #328 names them (user's choice
   3). The signal, update, child-to-parent and timer pairs keep the gap for now. A follow-up issue
   applies the same protocol there; the fact each resume awaits is a signal, an update, a child's
   outcome or a fired timer, not an activity id.

The at-least-once contract is stated in the user documentation: a resume may be delivered more
than once and before the fact it announces, and replay makes a second delivery harmless.

## Consequences

- **Port change.** `WorkflowResumeDispatcher` needs a way to send without deferral, plus the
  awaited activity id. Every implementation changes: Messenger, Laravel, Temporal (a no-op there),
  and the null one. Third-party implementers get an `UPGRADE.md` entry.
- **Message compatibility.** A `ResumeWorkflowMessage` serialized before the upgrade has no awaited
  id. Reading it back must not fail. It is handled on unserialize, and a test reads an old payload.
- **A redelivered attempt runs again.** Until the processor skips an attempt whose outcome is
  already journalled, a redelivery can append a second outcome, as a retry already can.
- **Tests.** A test kills a worker between the send and the append, on a persistent transport, and
  shows that a fresh worker completes the run. A thrown exception does not model a kill (`finally`
  blocks run, and the deferred send is discarded), so the test uses a subprocess that kills itself.
- **Temporal is unaffected.** The server owns delivery, and its dispatcher is a no-op.
- **#505** (the DBAL journal's optimistic concurrency) builds on this contract: a second resume
  of one execution is now expected, not exceptional.
