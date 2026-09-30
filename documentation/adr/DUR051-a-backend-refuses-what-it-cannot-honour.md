# DUR051: One command port, and a backend refuses by name what it cannot honour

## Status

Proposed. An agent drafted this ADR for #331. `documentation/adr/` is supervised, so it takes
effect only when the user approves it on its pull request.

The user decided the direction on 2026-09-28: one port, with explicit refusals. The three choices
under "Open for approval" are not decided yet.

Related:
- [DUR036](DUR036-nexus-caller-only-and-the-backend-asymmetry.md), whose Nexus refusal is the model
  followed here. DUR036 is not amended.
- [DUR045](DUR045-serving-a-nexus-operation.md).

## Context

A workflow's commands go through one port, `WorkflowCommandBufferInterface`. It has fourteen
methods and two implementations: the journal's `EventStoreCommandBuffer` and Temporal's
`TemporalWorkflowCommandBuffer`. Not every method means something on both backends, and today the
gaps are handled in two opposite ways.

- **Journal backend: a named refusal.** Its two Nexus methods throw
  `NexusUnsupportedByBackendException` (DUR036). A workflow that calls one fails immediately, and
  the message says which backend to use.
- **Temporal backend: empty bodies.** Three methods do nothing. The port's docblocks describe this
  as intentional (audit finding M10).
  - `completeChildWorkflow()` and `failChildWorkflow()` record the outcome of a child run
    **inline**. On Temporal they are unreachable today, because `TemporalChildWorkflowRunner`
    always defers the start, so `ExecutionContext` never gets as far as calling them. If a future
    wiring change made one reachable, the child's outcome would be dropped without a word.
  - `recordUpdateHandled()` is different. `WorkflowEnvironment` calls it for **every** update that
    an execution handles, on both backends. On Temporal the empty body is the correct behaviour,
    because the server writes `UPDATE_ACCEPTED` and `UPDATE_COMPLETED` from the protocol messages
    the worker hands back (`UpdateProtocol`). This is a delegation, not a gap: nothing is lost.

`NullEventStore` has the same flaw, one layer down (audit finding M11). Its `readStream()` returns
`[]`, which is indistinguishable from a run that has not started. A workflow wrongly given this
store would run its activities again instead of failing. Its only user is the `ExecutionRuntime`
that `WorkflowTaskRunner` builds for the Temporal worker. On that path the store is never read, and
that is the assumption a silent `[]` hides.

Two shapes were considered in #331:
- (a) split the port into a common part plus backend-specific capability ports;
- (b) keep one port and make every gap an explicit refusal.

## Decision

**Option (b)**, the user's decision: one port. What a backend cannot honour, it refuses by name.

1. **A refusal is an exception, not an empty body.** A port method that a backend cannot honour
   throws `UnsupportedByBackendException`, a new core exception that implements
   `Gplanchat\Durable\Exception\ExceptionInterface`. Its message names three things: the backend,
   the method, and what to use instead. This is the Nexus refusal, generalised.
2. **On Temporal, `completeChildWorkflow()` and `failChildWorkflow()` refuse.** The server writes
   a child's outcome into the parent's history, and an inline child is not a Temporal concept.
   Today no path reaches them, so nothing changes for a working application. What changes is that
   a future mistake becomes an error instead of a lost outcome.
3. **`NullEventStore` stops pretending.** Reading a stream from it throws. It must never say "this
   run has no history" when the truth is "there is no journal here". See choice 2 for how far this
   goes.
4. **The Nexus refusal stays as it is.** `NexusUnsupportedByBackendException` keeps its class and
   its messages. See choice 3.
5. **The port documents each backend's answer.** Each method's docblock says what each backend
   does with it: honours it, delegates it to the server, or refuses it, together with the
   exception it throws. The port no longer says "deliberately a no-op" anywhere.

## Open for approval

Each of these three shapes the implementation. A recommendation is given for each; the user
decides.

1. **`recordUpdateHandled()` on Temporal.** It cannot refuse: every update on Temporal goes
   through it, and a refusal would break them all.
   - *Recommended:* **keep the empty body as a documented delegation.** The port states that a
     backend whose server records updates does nothing here. The Temporal method's docblock names
     the protocol that does the recording. A Temporal integration test then shows the history
     carries `UPDATE_ACCEPTED` and `UPDATE_COMPLETED` for an update the worker handled. With that,
     the body is not silent: the contract and a test both say where the record comes from. This
     meets #331's "no silently empty implementation" without splitting the port.
   - *The tension, stated plainly:* #331's Done-when asks for no silently empty implementation
     under `src/Bridge/Temporal`, and this keeps one empty body. It reads the Done-when as "no body
     that is empty **and** unexplained", with the explanation carried by the contract and pinned by
     a test. Approving the recommendation approves that reading. If the Done-when is meant
     literally, the alternative below is the one that meets it.
   - *Alternative:* the buffer states whether it records updates itself (for example
     `recordsUpdatesItself(): bool`), and `ExecutionContext` skips the call when it does not. There
     is then no empty body at all, but the port gains a capability question, which is a small dose
     of the split that option (a) was.
2. **`NullEventStore` beyond `readStream()`.** The Temporal worker's runtime must never read from
   or write to a local journal.
   - *Recommended:* **replace it with `NoLocalJournalEventStore`.** Every method throws
     `UnsupportedByBackendException`: `append()`, both reads, and the count. The name says what it
     is, and the Temporal integration suites prove that no path reaches it. `NullEventStore` is
     then deleted, with an `UPGRADE.md` entry for any application that used it.
     - *No Rector rule for it.* A `RenameClassRector` from `NullEventStore` to the new class would
       compile, and it would silently change the behaviour. An application that used the old class
       as a sink would be handed a store that throws on its first write. Only the application knows
       which of the two it meant, so `UPGRADE.md` is the migration: an in-memory store if it wanted
       one, the new class if it wanted the guarantee.
   - *Alternative:* keep `NullEventStore` under its name, and make only the three read methods
     throw. `append()` stays a silent sink. That is a smaller change, and a write on a backend
     without a local journal would still vanish unseen.
3. **One family of refusals, or two classes.**
   - *Recommended:* **leave `NexusUnsupportedByBackendException` final and separate.** Both classes
     implement `ExceptionInterface`, so an application that catches every Durable error catches
     both. No application code has reason to catch "any refusal" specifically.
   - *Alternative:* make the Nexus class extend the new one. That gives one `catch` for every
     refusal, and removing `final` from a published class is a change to its extension contract.

## Consequences

- **The port's documentation changes. Its signatures do not.** No method is added or removed.
  Third-party buffers keep compiling. The port docblocks tell their authors to refuse instead of
  ignoring.
- **UPGRADE.md** gets an entry only where a caller could rely on the old behaviour:
  - under choice 2's recommendation, `NullEventStore` goes;
  - on Temporal, the two child methods now throw, although no path of the component calls them
    there.
- **Implementation note.** In `ExecutionContext`, a refusal thrown by `completeChildWorkflow()`
  is caught by the same `catch (\Throwable)` as the child's own failure, and reported through
  `failChildWorkflow()`, which would refuse in turn. The refusals must reach the caller
  unchanged: the catch lets `UnsupportedByBackendException` through, and a test pins it.
- **Tests.**
  - Each refusal is pinned with its message.
  - The Temporal integration suites run unchanged. That is the proof that no working path reaches
    a refusal or the local-journal store.
  - Under choice 1's recommendation, one integration test shows the server recording an update.
- **Audit findings.** M10 and M11 close with this change.
