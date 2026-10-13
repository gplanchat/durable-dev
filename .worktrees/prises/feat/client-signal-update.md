# feat/client-signal-update

- **Scope**: #973, #974 (task 2.1 of the workflow-repository change): signal and update through `InMemoryWorkflowClient`, the update's result read from the `WorkflowUpdateHandled` that carries the caller's update id.
- **Entries**: `src/Durable/Store/InMemoryWorkflowClient.php` (signal and update only), `src/Durable/Testing/WorkflowClientPortConformanceTestCase.php`, `tests/unit/Durable/Store/InMemoryWorkflowClientConformanceTest.php`.
- **Overlap**: the other tasks of the workflow-repository change touch the same client and conformance case (2.2 to 2.7).
- **State**: in progress.
