# refactor/bridge-no-bundle-import

- **Scope**: #345 — the Temporal bridge stops importing the Symfony bundle. A narrow core port,
  `Gplanchat\Durable\Debug\WorkflowDispatchObserverInterface`, carries `onWorkflowDispatchRequested()`;
  `DurableExecutionTrace` implements it and `TemporalWorkflowResumeDispatcher` is typed against it.
  No class moves.
- **Entries**: `src/Durable/Debug/WorkflowDispatchObserverInterface.php` (new),
  `src/DurableBundle/Profiler/DurableExecutionTrace.php` (implements line),
  `src/Bridge/Temporal/Port/TemporalWorkflowResumeDispatcher.php`, one "New" line in `UPGRADE.md`,
  new tests under `tests/unit/Bridge/Temporal/` and `tests/unit/`.
- **State**: pushed, PR held (epic #307 WIP full) — vera; reviewer bob.
