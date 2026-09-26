# fix/continue-as-new-keeps-execution-id

- **Scope**: #560. The bridge's continue-as-new command copies the `durableExecutionId` memo. Without
  it the successor run has no memo, and the worker falls back to the workflow id, so it runs as
  `durable-<sanitised id>` instead of the id the application started it with. Measured on 1.25.2.
- **Entries**: `src/Bridge/Temporal/Worker/TemporalWorkflowCommandBuffer.php` (`continueAsNew()`,
  the memo line just before `applyWorkflowTimeouts()`; alice's search-attribute copy goes after it),
  a unit test, a Temporal integration test.
- **State**: in progress — jane. Reviewer: jack. Blocks #514 (also jane's, waiting on the user's call).
