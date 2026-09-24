# chore/dead-code-temporal-bridge

- **Scope**: #372 slice 1, the part no other prise holds: delete `TemporalJournalGrpcPoller` and
  `JournalWorkflowTaskProcessor`, drop `JournalExecutionIdResolver::durableExecutionIdFromHistory()`,
  move `TemporalEventConverter` from `Profiler/` to `Store/`, move the native-execution spike and its
  command and test to `symfony/` (alice, 2026-09-24: move, not delete; the owner can overrule).
  Slice 2 (`Grpc/`, `Messenger/`, the `TemporalApplicationTransport` note) waits for #353 and #333.
- **Entries**: `src/Bridge/Temporal/TemporalJournalGrpcPoller.php`, `src/Bridge/Temporal/Journal/`,
  `src/Bridge/Temporal/Worker/{WorkflowTaskProcessor,TemporalWorkflowCommandBuffer}.php`,
  `src/Bridge/Temporal/Profiler/`, `src/Bridge/Temporal/Store/`, `src/Bridge/Temporal/Spike/`,
  `symfony/src/Command/DurableTemporalNativeSpikeCommand.php`, `symfony/tests/Integration/Temporal/`
  (spike test only), their tests. The `phpstan.neon`/`psalm.xml` lines go in commits stacked on
  #498's branch; PR held until #498 merges.
- **State**: pushed on `chore/dead-code-temporal-bridge` (stacked on #498), PR held until #498 merges — sabrina.
