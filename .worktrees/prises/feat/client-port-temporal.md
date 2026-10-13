# feat/client-port-temporal

- **Scope**: #974, #971: tasks 3.1, 3.2 and 3.3 of the `workflow-repository` change. The Temporal implementation of `WorkflowClientPort`, `cancel()` and `terminate()` re-keyed by `ExecutionId` with the "already ended" mapping, and an already started workflow failing the port's start.
- **Entries**: `src/Bridge/Temporal/WorkflowClient.php`, `src/Bridge/Temporal/Port/`, `tests/unit/Bridge/Temporal/`, `tests/integration/Temporal/`
- **State**: in progress
