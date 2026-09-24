# chore/dead-code-temporal-grpc

- **Scope**: #372, slice 2 — the 15 uncalled control-plane wrappers of
  `Grpc/WorkflowServiceActivityRpc`, `WorkflowServiceExecutionRpc::pollWorkflowExecutionUpdate()`,
  the stale "Replaces …" sentences of `Grpc/TemporalHistoryCursor` and
  `Messenger/TemporalJournalTransport`, and an UPGRADE table like #493's. The "Later: substitute"
  note went with `TemporalApplicationTransport` in 6d515ae1.
- **Entries**: `src/Bridge/Temporal/Grpc/WorkflowServiceActivityRpc.php`,
  `src/Bridge/Temporal/Grpc/WorkflowServiceExecutionRpc.php`,
  `src/Bridge/Temporal/Grpc/TemporalHistoryCursor.php`,
  `src/Bridge/Temporal/Messenger/TemporalJournalTransport.php`, `UPGRADE.md`.
- **State**: in progress — vera; reviewer sabrina.
