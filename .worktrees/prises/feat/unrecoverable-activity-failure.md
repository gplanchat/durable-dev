# feat/unrecoverable-activity-failure

- **Scope**: #341 — a non-retryable activity failure reaches Messenger as
  `UnrecoverableMessageHandlingException` from the bundle's `ActivityRunHandler`, and the failures
  page says which retry knob lives where.
- **Entries**: `src/Durable/Worker/ActivityMessageProcessor.php`,
  `src/DurableBundle/Handler/ActivityRunHandler.php`, `documentation/user/failures/`, their tests.
- **State**: in review — PR #484, durable-50 (lane A).
