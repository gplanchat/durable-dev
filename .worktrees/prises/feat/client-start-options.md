# feat/client-start-options

- **Scope**: #974, tasks 2.6 and 2.7 of `workflow-repository`: the start options of the in-memory client port (id reuse policy, execution and run timeouts honoured; the others refused), and the SQL-backed start (insert, duplicate key, conditional update after an ended execution).
- **Entries**: `src/Durable/Store/InMemoryWorkflowClient.php`, the metadata stores, `tests/unit/`.
- **Overlap**: `InMemoryWorkflowClient.php` and its conformance test, with the signal/update, query, cancel/terminate and result slices of #974.
- **State**: in progress.
