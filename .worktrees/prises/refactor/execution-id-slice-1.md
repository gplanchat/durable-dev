# refactor/execution-id-slice-1

- **Scope**: #682, first slice: the static journal readers (ActivityEventJournal, WorkflowQueryEvaluator, ParentChildWorkflowCoordinator::isChildRunActive) accept an ExecutionId.
- **Entries**: src/Durable/Store, src/Durable/Query, src/Durable, tests, UPGRADE.
- **State**: in progress.
