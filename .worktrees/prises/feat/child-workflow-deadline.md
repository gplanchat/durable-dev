# feat/child-workflow-deadline

- **Scope**: #979, a child's execution timeout is applied on the journal backends through a new `ChildWorkflowDeadline` event (owner decision of 13 October). Epic #970.
- **Entries**: src/Durable/Event/ChildWorkflowDeadline.php, src/Durable/Mapping/EventDataMapper.php, src/Durable/ChildWorkflowRunner.php, src/Durable/InMemoryWorkflowRunner.php, src/Durable/Store/EventStoreCommandBuffer.php, src/Durable/Testing/EventStoreConformanceTestCase.php, tests/unit/Durable/, documentation/user/concepts/, UPGRADE.md.
- **Overlap**: feat/child-timeouts-journal (same ticket, an earlier failing test only; this branch does not build on it); UPGRADE.md at the end of Unreleased.
- **State**: in progress.
