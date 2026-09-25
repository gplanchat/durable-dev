# fix/temporal-activity-timeout-fails-the-workflow

- **Scope**: #544 — `TemporalExecutionHistory` reads `ACTIVITY_TASK_TIMED_OUT` and raises the same
  `DurableActivityFailedException` the journal backends raise for an activity timeout; a run left
  stuck by the bug fails on its next workflow task.
- **Entries**: `src/Bridge/Temporal/Worker/TemporalExecutionHistory.php`, its unit tests,
  `tests/integration/Temporal/` (a timing-out activity and its test).
- **State**: in review, PR #546 — dave.
