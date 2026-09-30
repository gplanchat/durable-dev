# DUR053: A superseded pass cannot write: one fencing epoch per pass

## Status

Proposed — drafted by an agent for #505. `documentation/adr/` is supervised: this ADR takes effect
when the user approves its text on its pull request. The shape (a per-pass fencing epoch), the
port (a capability interface beside `EventStoreInterface`, no break) and the decision to write this
ADR are the user's decisions of 2026-09-28, on #505.

Builds on [DUR050](DUR050-the-resume-is-dispatched-first.md), under which a second resume of one
execution is expected, and on [DUR041](DUR041-store-parity-is-a-suite-every-adapter-runs.md), whose
conformance suite carries the new guarantee. Neither is amended.

## Context

On the DBAL and Illuminate backends, a lock is the only thing that keeps two workers from replaying
one execution at the same time: `SingleResumeLockMiddleware` on Symfony, `ResumeLock` on Laravel.
A lock has a time to live. A pass that outlives it, or a worker that pauses long enough, loses the
lock while it still runs, and a second resume takes over.

Since #503 the losing pass finds out at its next step boundary, where the middleware throws. That
exception travels the same path as a broker outage, so the pass can survive it: workflow code may
catch what `$ctx->activity()` throws, and `ExecutionContext` records a failed child around
`runChild()`. The pass then goes on appending. `DbalEventStore::append()` states no expectation
about the journal, so both passes write to it, and the journal diverges (#505).

Two facts shape the fix:

- **Not every writer is a pass.** Besides the pass holding the lock, facts land in a journal at any
  time: activity outcomes (`ActivityMessageProcessor`, and #610's `ActivityRetryQueued`), signals
  and updates, a child's outcome in its parent (`ResumeWorkflowHandler`), a parent's cancellation
  in its child (`ParentChildWorkflowCoordinator`). Some land *inside* a live pass: the inline
  activity drain (`ExecutionRuntime::drainActivityQueueOnce()`) and `sync://` activities run the
  activity processor in the pass itself.
- **A pass that stops loses no wakeup.** A second resume of a locked execution is kept, not
  dropped. On Symfony, `SingleResumeLockMiddleware` acquires blocking, so the resume waits its turn.
  On Laravel, `ResumeWorkflowJob` calls `ResumeLock::tryAround()`, and when the lock is taken it
  dispatches the resume again through `ResumeDeferral`, failing only past `max_deferrals`. Under
  DUR050 every fact that needs a pass sends a resume. A pass that is refused can simply stop: the
  resume that follows replays the execution.

Two shapes were considered on #505:

- **(A) Expected position.** Each append states the journal length it expects, backed by a
  `sequence` column and a unique `(execution_id, sequence)`. Every external fact moves the length,
  including the activities that run inside a pass, so an ordinary pass would be refused on ordinary
  traffic, with a retry loop and a livelock risk to follow. Every existing row would need a
  `sequence` backfill.
- **(B) A fencing epoch per pass.** A pass claims the next epoch of its execution when it starts,
  and its appends carry it. Only a newer pass makes an older one stale, which is exactly the lost
  lock. External facts carry no epoch and invalidate nothing.

## Decision

**Option (B)** (the user's decision), behind a capability interface (the user's decision).

1. **The port is a capability, not a change to `EventStoreInterface`.** A new
   `FencedEventStoreInterface extends EventStoreInterface` adds two methods:
   - `claimPass(string $executionId): PassFence` makes the execution's epoch one higher and returns
     it;
   - `appendFenced(Event $event, PassFence $fence): void` appends only while `$fence` is the
     execution's current epoch, and throws `SupersededPassException` otherwise.

   `PassFence` is a readonly value object (the execution id and the epoch). A store that does not
   implement the interface keeps today's behaviour; nothing breaks for third-party stores.
2. **Who implements it.** The InMemory, DBAL and Illuminate stores. `ProjectingEventStore`
   implements it and forwards when its inner store does; over a store without the capability, its
   `claimPass()` returns `PassFence::none()`, which `appendFenced()` treats as a plain append.
   `TemporalReadThroughEventStore` does not implement it: on Temporal the server orders an
   execution's workflow tasks.
3. **Storage.** One heads row per execution: `durable_execution_heads (execution_id, epoch)`, an
   absent row meaning epoch 0. The events table does not change, so no row is backfilled. The DBAL
   `DurableSchema` declares the table (and `DurableSchemaListener` shows it to Doctrine's schema
   tools); the Illuminate bridge ships a migration.
4. **Atomicity.** A claim must not fall between a stale pass's check of the epoch and its insert,
   and a newer pass reads the history only after its claim has committed, so that it sees every
   append the older pass made before it.
   - On MySQL and PostgreSQL, `claimPass()` writes the heads row, which locks it, and
     `appendFenced()` reads the epoch under a shared lock on that row and inserts the event in the
     same transaction.
   - SQLite has no row locks, but it admits one writer at a time. There, `appendFenced()` is a
     single conditional `INSERT … SELECT … WHERE` the head's epoch equals the fence's, and
     `claimPass()` is a single upsert. Two write statements never interleave, so the check and the
     insert cannot straddle a claim. An `SQLITE_BUSY_SNAPSHOT` raised under WAL is a lost race, and
     the store rethrows it as `SupersededPassException`.
5. **Where a pass claims.** Every place that assembles a pass claims once, before it reads the
   history, when the store has the capability: `ExecutionEngine::start()` and `::resume()`,
   `FireWorkflowTimersHandler`, and `InMemoryWorkflowRunner`. The pass's writers receive a
   pass-scoped decorator whose `append()` calls `appendFenced()` with that fence. That covers
   `EventStoreCommandBuffer`, `EventStoreWorkflowLifecycle`, `ExecutionStarted` in
   `ExecutionEngine::start()`, and `TimerCompleted` in `ExecutionRuntime`, which today appends
   through its own store and moves to the pass's. External writers keep the undecorated store.
6. **What a refused pass does.** `SupersededPassException` is a core exception. `ResumeWorkflowHandler`
   and `FireWorkflowTimersHandler` catch it at the top, acknowledge their message and stop: the newer
   pass owns the execution.
7. **Workflow code cannot talk its way past it.** Workflow code that catches the exception gains
   nothing. Every later append of that pass carries the same stale fence and is refused as well,
   and nothing is dispatched, because `EventStoreCommandBuffer::scheduleActivity()` appends
   `ActivityScheduled` before it enqueues the activity.

## Consequences

- **No break.** `EventStoreInterface` is unchanged. `UPGRADE.md` gets an entry for the new table
  (Doctrine users see it in `doctrine:migrations:diff`; Laravel users run `migrate`) and for
  third-party store authors who want the guarantee.
- **Conformance.** `EventStoreConformanceTestCase` gains the fencing cases, run against stores that
  implement the capability. They run on SQLite in the root suite, and on MySQL 8.4 and PostgreSQL
  16 in the SQL conformance jobs. An `expectsFencedPasses()` hook (false by default, true for InMemory,
  DBAL and Illuminate) makes a store that should fence and does not fail the suite. The cases:
  - two passes claimed in turn: the older pass's append is refused and the newer one's accepted;
  - an external append between them is accepted;
  - on the DBAL and Illuminate stores, workflow code that catches `SupersededPassException` does
    not write to the journal.
- **One more write per pass.** A claim is one upsert on a small table, next to the reads a replay
  already makes.
- **A known limit: the handler's own writes after the pass.** A stale pass that swallows every
  refusal can still reach the end of `ResumeWorkflowHandler`. There it records the wait in the run
  catalogue and may send a resume or a timer wake. None of these writes the journal. An extra
  resume or wake is harmless under DUR050. A stale wait in the catalogue lasts until the next pass
  records its own. Checking the fence again before those writes is the next step if that ever
  matters.
- **The lock stays.** It keeps two passes from replaying at once in the common case; the fence
  makes the rare case, a lost lock, safe rather than silent.
- **Temporal is unaffected**, and so is a third-party store until its author opts in.
