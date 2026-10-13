# feat/client-result-bound

- **Scope**: #974, task 2.3 of the `workflow-repository` change: `result()` with a bound on the in-memory client; a failed execution rethrows its failure.
- **Entries**: src/Durable/Store/InMemoryWorkflowClient.php, src/Durable/Failure/, src/Durable/Testing/WorkflowClientPortConformanceTestCase.php, tests/unit/Durable/.
- **Overlap**: the siblings of #974 on InMemoryWorkflowClient and the conformance case (tasks 2.1, 2.2, 2.4 to 2.7).
- **Session**: durable-fixer, 2026-10-13.
- **State**: in progress.
