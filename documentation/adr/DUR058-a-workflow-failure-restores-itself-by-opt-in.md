# DUR058: A workflow failure restores itself by opt-in

## Status

Proposed. ADRs in `documentation/adr/` are supervised, so this one takes effect when the user
approves its text on its pull request.

The user chose option (d) of #872 on 2026-10-01. Options (a) to (d) are listed in PR #928, which
covers the activity half of the ticket.

Related:
- [DUR011](DUR011-errors-retries-and-classification.md): errors are classified, and a cause is kept
  for diagnosis. The opt-in restore of activity failures implements it in code, and this ADR
  mirrors that restore for workflow failures.
- [DUR055](DUR055-a-payload-codec-at-the-client-boundary.md): the payload codec at the client
  boundary, which encodes the failure details this ADR adds to.

## Context

A caller waits for a workflow's result through `WorkflowClient::pollForCompletion()`. When the
workflow throws an exception it does not catch, the caller sees a different exception depending on
the backend:

- **On the journal backends** (in-memory, DBAL, Illuminate), `EventStoreWorkflowLifecycle::onFailed()`
  rethrows the object the workflow threw. The journal is the append-only sequence of events that
  records what an execution decided and received; see the [glossary](../user/glossary/_index.md).
  An unhandled activity failure comes back wrapped in `DurableWorkflowAlgorithmFailureException`.
- **On Temporal**, `pollForCompletion()` throws a bare `\RuntimeException('Workflow "<id>" failed:
  <message>')` with no previous exception. PR #928 restores the activity half: the four activity
  kinds now throw `DurableWorkflowAlgorithmFailureException`, with an `ActivityFailureCauseException`
  stand-in as previous. A stand-in is an exception that carries the original class name as text,
  because the original class is not rebuilt. The workflow's own exception still comes back as a
  bare `\RuntimeException`.

A caller that writes `catch (OrderRejected $e)` around `pollForCompletion()` therefore catches it on
the journal backends and not on Temporal. Every host that waits for a Temporal result goes through
this method: the Symfony bench runner, Laravel's `WorkflowClientInterface` binding, the Sylius and
Symfony Nexus demo commands, and Magento's `run()`.

What the worker writes today, in `TemporalWorkflowCommandBuffer::failWorkflow()`:

- `ApplicationFailureInfo.type`: the exception class, the field the server matches against
  non-retryable error types;
- `Failure.message`: the exception message;
- `ApplicationFailureInfo.details[0]`: the JSON of `WorkflowExecutionFailed::payload()`, that is
  `kind`, `failureClass`, `failureMessage`, `failureCode` and `context`. For a workflow's own
  exception the kind is `workflow_handler_failure` and the context is empty.

`TemporalEventConverter::decodeApplicationFailureDetails()` reads that payload back into a
`WorkflowExecutionFailed`. Nothing in it lets the client rebuild the exception.

Activities already solve the same problem by opt-in. An exception that implements
`Gplanchat\Durable\Port\DeclaredActivityFailureInterface` returns a context from
`toActivityFailureContext()`. `FailureEnvelope::fromThrowable()` stores it under
`_durable_declared`, `_durable_declared_class` and `_durable_declared_payload`.
`DurableActivityFailedException::toThrowable()` calls `restoreFromActivityFailureContext()` on
replay, and falls back to the generic exception, with the reason in its message, when the restore
throws. Only a class that implements the interface is ever instantiated.

## Decision

1. **The core defines `Gplanchat\Durable\Port\DeclaredWorkflowFailureInterface`**, beside
   `DeclaredActivityFailureInterface`, with the same shape:

   ```php
   interface DeclaredWorkflowFailureInterface extends \Throwable
   {
       /** @return array<string, mixed> JSON-serialisable values only */
       public function toWorkflowFailureContext(): array;

       /** @param array<string, mixed> $context */
       public static function restoreFromWorkflowFailureContext(array $context): static;
   }
   ```

2. **The classified failure carries the declared context.** When `WorkflowFailureClassifier`
   classifies an exception as `workflow_handler_failure` and the exception implements the interface,
   the context of `WorkflowExecutionFailed` holds three keys, named after the activity ones:
   `_durable_declared` (`true`), `_durable_declared_class` (the class) and
   `_durable_declared_payload` (the result of `toWorkflowFailureContext()`). `failWorkflow()` writes
   that payload into `details[0]` as today. `type` and `Failure.message` do not change.
3. **The client restores it.** `pollForCompletion()` decodes the close event, and when the context
   names a class that implements `DeclaredWorkflowFailureInterface`, it throws
   `$class::restoreFromWorkflowFailureContext($payload)`. The check is
   `is_a($class, DeclaredWorkflowFailureInterface::class, true)` before any call, so no other class
   is instantiated. The restore lives in one core function, so another reader of a failed run can
   call it.
4. **A class that does not opt in keeps the default:** the bare `\RuntimeException` with the
   `Workflow "<id>" failed: ` prefix and no previous, as after #928. That is option (c).
5. **A restore that fails falls back to the default.** If the class does not load, does not
   implement the interface, or its restore throws, the client throws the default exception and
   appends the reason to its message, as `toThrowable()` does for activities.
6. **The activity kinds are unchanged.** The classifier keeps its order: catastrophic, activity,
   superseded, Nexus, deadline, declared activity failure, then the workflow's own exception. A
   class that implements both interfaces and escapes the workflow is classified as an unhandled
   declared activity failure, and comes back as `DurableWorkflowAlgorithmFailureException`, as on
   the journal.

## Alternatives

- **(a) Rebuild Durable's own types** (`DeadlineExceededException`,
  `DurableNexusOperationFailedException`) from the context the worker stores. Durable owns these
  classes and their context, so this adds no contract. It does not reach an application's own
  exception, which is what #872 reports. It stays possible next to this decision, as its own change.
- **(b) Instantiate any class that loads**, is a `\Throwable` and declares no constructor of its
  own. A `\LogicException` or an `\Error` rebuilt this way escapes a `catch (\RuntimeException)`
  around `pollForCompletion()`, which catches every failure the client throws today. The
  constructor check is a heuristic: a class whose constructor takes other arguments falls back
  without notice, and any class named in a history that passes the check is autoloaded and built,
  whether its author meant it or not. Rejected.
- **(c) Never instantiate**, and always throw a stand-in or a typed wrapper, as for child
  workflows. It is backward compatible and is the state after #928, but catching by class keeps
  working on the journal backends and not on Temporal. Kept as the default for classes that do not
  opt in.

## Consequences

- **Public API.** One new interface in `Gplanchat\Durable\Port`. No existing signature changes.
- **Payload format.** The three keys are additive. A failure written before this change has none
  of them and falls back to the default. A failure written by another SDK has no `kind`, or details
  that are not JSON, and must fall back too. Today `JsonPlainPayload::decode()` throws
  `\JsonException` on details that are not JSON, which escapes `catch (\RuntimeException)`. The
  implementation catches it, as the review of #928 recommends.
- **The journal event changes too.** `WorkflowExecutionFailed` is the journal event as well as the
  Temporal details, so the in-memory and SQL journals, the profiler and the dashboards store and
  show the declared context.
- **Encoding.** Under DUR055 the codec encodes every payload, so the declared context in `details`
  is encoded. The failure message stays in clear, as DUR055 decision 6 documents.
- **A client that cannot load the class**, such as another application or a Nexus caller, gets the
  default. A restored exception carries only what its context holds: no trace and no previous.
- **Docs, EN and FR.** No user page documents `DeclaredActivityFailureInterface` today. The new
  interface goes under `documentation/user/failures/`, and the parity note of #928
  (`{#waiting-for-the-result}`) names it.
- **UPGRADE.** One additive entry: nothing changes unless an exception implements the interface.
  An exception that implements it comes back as its own class, so one that does not extend
  `\RuntimeException` no longer reaches a `catch (\RuntimeException)`. The entry says so.
- **Rector.** No rule. Nothing existing is renamed or removed.
- **The signal wait stays different and out of scope.** A workflow that waits on a signal no one
  sends fails at once in memory (`WorkflowStuckException::noProgress()`), and on Temporal the
  caller waits until `WorkflowStuckException::pollsExhausted()`. The docs note of #928 records it.

## Open questions

1. **Restore arguments.** The restore takes the context only, as for activities. Passing the
   recorded message and code as well would spare each class from copying them into its context.
2. **The previous of a declared activity failure.** `unhandledDeclaredActivityFailure()` writes an
   empty context, so on Temporal the wrapper's previous cannot be restored through
   `restoreFromActivityFailureContext()`. Restoring it needs a worker change, and belongs either
   here or in a follow-up.
3. **A typed default.** The default stays a bare `\RuntimeException`. A stand-in class that carries
   the failure class as text, as `DurableChildWorkflowFailedException` does, would let a caller read
   the class without opting in.
4. **Child workflows.** On the journal the parent sees `DurableChildWorkflowFailedException`, not
   the child's class. This ADR leaves child failures as they are on both backends.
5. **The docs page.** Which page under `documentation/user/failures/` holds both interfaces.
