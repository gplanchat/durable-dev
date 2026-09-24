# fix/profiler-container-batch

- **Scope**: #337 — B-4 dispatch label, B-6 stale class names, B-7 private `.inner`, B-12 diagnose
  on Temporal, B-13 documented observer trade, dead trace/collector code, `WorkflowTaskProcessor`
  private.
- **Entries**: `src/DurableBundle/Command/DiagnoseExecutionCommand.php`,
  `src/DurableBundle/DataCollector/DurableDataCollector.php` (`getTimeFrame()` only; the row builders
  stay sabrina's), `src/DurableBundle/Profiler/DurableExecutionTrace.php`,
  `src/DurableBundle/Messenger/{MessengerWorkflowResumeDispatcher,WorkflowRunDispatchProfilerMiddleware,NewWorkflowRunStamp}.php`,
  `UPGRADE.md` (one entry), their tests. After #485: `DurableExtension` (`.inner`, the diagnose
  argument, `WorkflowTaskProcessor` visibility) and `DurableContainerSurfaceTest`. B-6 stacks on
  `feat/profiler-run-event-phase` for `DurableProfilerEventPresentation.php` and `durable.html.twig`.
- **State**: in progress — antoine, worktree `.claude/worktrees/profiler-container-batch`. Reviewer: sabrina.
