# feat/workflow-task-events

- **Scope**: #850 item 3: journal `WorkflowTaskScheduled`, `WorkflowTaskStarted`, `WorkflowTaskCompleted` so the dashboards show the wait for a worker.
- **Entries**: `src/Durable/Event`, `EventDataMapper`, `ResumeWorkflowHandler`, dispatch call sites, `JournalRunHistoryReader`, UPGRADE.
- **Overlap**: fix/dashboard-behaviours (#850 items 1-2, `{key, params}` message value), not touched here.
- **State**: in progress
