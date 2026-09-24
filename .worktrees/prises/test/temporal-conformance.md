# test/temporal-conformance

- **Scope**: #326 box 2, first part: Temporal conformance classes for `TemporalJournalEventStore`
  (port tier) and `TemporalWorkflowRunCatalog`. They go in the root integration suite, gated on
  `DURABLE_TEMPORAL_ADDRESS`. The replay-tier class waits on #325
  (`refactor/history-source-value-objects`) and ships as a second PR. `TemporalReadThroughEventStore`
  gets no class (#333, #331).
- **Entries**: `tests/integration/Temporal/TemporalJournalEventStoreConformanceTest.php`,
  `tests/integration/Temporal/TemporalWorkflowRunCatalogConformanceTest.php`, the DUR041 docblock
  in `src/Durable/Testing/EventStoreReplayConformanceTestCase.php`.
- **State**: in progress — dave.
