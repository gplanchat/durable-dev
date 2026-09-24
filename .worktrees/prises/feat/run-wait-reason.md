# feat/run-wait-reason

- **Scope**: #324 — a suspended run's wait reason (condition, timer due at T, activity Y attempt N)
  is persisted in the run projection and shown on `WorkflowRunDescription` / `RunDashboard`.
  Plugin and Magento panels stay with lane D.
- **Entries**: `src/Durable/Handler/ResumeWorkflowHandler.php`, `src/Durable/Store/ProjectingEventStore.php`,
  `src/Durable/Observation/`, `src/Durable/Awaitable/AwaitableInspector.php`, the DBAL and
  Illuminate run catalogues and schemas, `documentation/user/`, their tests.
- **State**: in progress — durable-50 (lane A).
