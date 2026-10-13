# fix/temporal-max-activity-retries

- **Scope**: #1051, the Temporal bridge reads max_activity_retries through the retry policy. Epic #970, decision 4 of #982.
- **Entries**: src/Bridge/Temporal/Worker/TemporalWorkflowCommandBuffer.php, src/Bridge/Temporal/Worker/WorkflowTaskRunner.php, src/Bridge/Temporal/TemporalRuntimeAssembly.php, host wiring (Symfony, Laravel, Magento), documentation/user/backends/ and configuration/.
- **Overlap**: none known.
- **Session**: durable-beta2 (worker), 2026-10-13.
- **State**: in progress.
