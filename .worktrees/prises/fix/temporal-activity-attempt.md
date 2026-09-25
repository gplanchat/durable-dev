# fix/temporal-activity-attempt

- **Scope**: #547 — on Temporal, an activity failure or timeout reports the attempt the server ran
  (`ActivityTaskStarted.attempt`, via `started_event_id`), as the journal backends do, instead of `1`.
- **Entries**: `src/Bridge/Temporal/Worker/TemporalExecutionHistory.php` (the `ACTIVITY_TASK_FAILED` and
  `ACTIVITY_TASK_TIMED_OUT` branches), their tests.
- **State**: in progress — antoine, worktree `.claude/worktrees/activity-attempt`. Reviewer: dave.
