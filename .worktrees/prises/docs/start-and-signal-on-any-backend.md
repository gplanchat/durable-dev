# docs/start-and-signal-on-any-backend

- **Scope**: #258, what #472 left open. Concepts shows `$client->signal()` as if it were the only way:
  document the backend-neutral signal (`DeliverWorkflowSignalMessage` on the bus) next to it; say
  which workflow type string `dispatchNewWorkflowRun()` takes; store the alias, not the FQCN, when a
  caller passes `::class` (Messenger and Laravel dispatchers).
- **Entries**: `documentation/user/concepts/`, `documentation/user/getting-started/` (EN + FR),
  `src/DurableBundle/Messenger/MessengerWorkflowResumeDispatcher.php`,
  `src/DurableLaravel/Queue/LaravelWorkflowResumeDispatcher.php`, their tests.
- **Not in scope**: a new `WorkflowClient` service; `dispatchNewWorkflowRun()` stays the entry point.
- **State**: in review, PR #549 — emma. Reviewer: elsa.
