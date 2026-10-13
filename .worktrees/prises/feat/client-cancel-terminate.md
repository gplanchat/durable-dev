# feat/client-cancel-terminate

- **Scope**: tasks 2.4 and 2.5 of the `workflow-repository` change (#971, #974): `cancel()` and `terminate()` of the port on the in-memory client, the `terminated` failure kind, the cancellation page.
- **Entries**: `src/Durable/Store/InMemoryWorkflowClient.php`, `src/Durable/Event/WorkflowExecutionFailed.php`, `src/Durable/Testing/WorkflowClientPortConformanceTestCase.php`, `documentation/user/cancellation/`, `documentation/user/failures/events*.md`, `UPGRADE.md`.
- **Overlap**: tasks 2.1 to 2.3 and 2.6 to 2.7 touch the same client and conformance case; hunks kept small.
- **State**: in progress.
