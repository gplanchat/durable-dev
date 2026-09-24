# fix/temporal-signal-update-delivery

- **Scope**: #333 — on Temporal native, `DeliverWorkflowSignalMessage` / `DeliverWorkflowUpdateMessage`
  reach the cluster through `WorkflowClientInterface::signal()` / `update()`, once: the message
  carries a request / update id across redeliveries and the client puts it on the wire. The
  journal handlers and the Messenger timer dispatcher / `FireWorkflowTimersHandler` are registered
  on the journal branch only.
- **Entries**: `src/Bridge/Temporal/Messenger/DeliverWorkflow{Signal,Update}ToTemporalHandler.php`
  (new; the rest of `Messenger/` stays arwen's, #353), `src/Bridge/Temporal/WorkflowClient{,Interface}.php`
  `signal()`/`update()`, `src/Durable/Transport/DeliverWorkflow{Signal,Update}Message.php`,
  `src/DurableRector/config/sets/durable-upgrade.php`, one `UPGRADE.md` entry, their tests, the
  test double in `symfony/tests/Unit/DurableSampleWorkflowRunnerRoutingTest.php`. Stacked on #485
  for `src/DurableBundle/DependencyInjection/` only: `DurableExtension::registerWorkflowControlHandlers()`
  and its test.
- **State**: in review — PR #515 (stacked on #485), antoine, reviewer arwen.
