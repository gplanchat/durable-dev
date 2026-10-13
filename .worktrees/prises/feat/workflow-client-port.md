# feat/workflow-client-port

- **Scope**: #974 task 1.1 of `openspec/changes/workflow-repository`: `WorkflowClientPort` keyed by `ExecutionId`, the conformance test case, the in-memory implementation. Not tasks 1.3, 2.x, 3.x, 4.x.
- **Entries**: src/Durable/Port/, src/Durable/Exception/, src/Durable/Testing/, src/Durable/Store/, tests/unit/Durable/.
- **Overlap**: `src/Durable/WorkflowRepository.php` (task 1.2, merged in #1039): the constructor gains the port in a later slice.
- **Session**: worker, 2026-10-13.
- **State**: in progress.
