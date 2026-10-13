# feat/update-handled-update-id

- **Scope**: #974, task 1.6 of workflow-repository: WorkflowUpdateHandled gains a nullable update id.
- **Entries**: src/Durable (WorkflowUpdateHandled, PendingUpdate, EventDataMapper, EventStoreCommandBuffer, ResumeWorkflowMessage), src/DurableBundle/Handler, tests, UPGRADE.
- **Overlap**: UPGRADE.md at the end of Unreleased; the sibling slices of #970.
- **State**: in progress.
