# fix/temporal-signal-update-delivery

- **Scope**: #333 — on Temporal native, `DeliverWorkflowSignalMessage` / `DeliverWorkflowUpdateMessage`
  reach the cluster through `WorkflowClientInterface::signal()` / `update()`; the journal handlers and
  the Messenger timer dispatcher / `FireWorkflowTimersHandler` are registered on the journal branch only.
- **Entries**: `src/Bridge/Temporal/Messenger/` (two new handlers), their tests. Last commit only,
  stacked on #485 for `src/DurableBundle/DependencyInjection/` only: `DurableExtension`'s
  `registerWorkflowControlHandlers()` and its test.
- **State**: in progress — antoine.
