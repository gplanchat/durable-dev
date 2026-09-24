# refactor/history-source-value-objects

- **Scope**: #325, shape fixes only (user decision, 2026-09-24) — `WorkflowHistorySourceInterface`
  returns readonly value objects instead of `array{…}` shapes; the fabricated timer `scheduledAt`
  goes; the side-effect result is wrapped like its siblings. `ExecutionId` type-hints and the
  Rector rule stay for a second step planned with #269.
- **Entries**: `src/Durable/Port/WorkflowHistorySourceInterface.php`, `src/Durable/Port/History/`,
  `src/Durable/Store/EventStoreHistorySource.php`, `src/Bridge/Temporal/Worker/TemporalExecutionHistory.php`,
  `src/Durable/ExecutionContext.php` and the other callers, `UPGRADE.md`, their tests.
- **State**: in progress — durable-50 (lane A).
