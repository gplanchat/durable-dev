# feat/client-query-readonly-replay

- **Scope**: #972 and #974 (task 2.2 of the `workflow-repository` change), a query on a journal backend replays the run read-only, calls the `#[AsQueryMethod]` handler and appends no event. Epic #970.
- **Entries**: src/Durable/Query/, src/Durable/Store/InMemoryWorkflowClient.php, src/Durable/Testing/, src/Durable/Exception/, tests/unit/Durable/.
- **Overlap**: src/Durable/Store/InMemoryWorkflowClient.php and the conformance case, shared with the slices for signal and update (2.1), result (2.3), cancel and terminate (2.4, 2.5) and start options (2.6, 2.7).
- **State**: in progress.
