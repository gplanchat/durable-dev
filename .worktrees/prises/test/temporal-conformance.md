# test/temporal-conformance

- **Scope**: #326 box 2, first part: Temporal conformance classes for `TemporalJournalEventStore`
  (port tier) and `TemporalWorkflowRunCatalog`. They go in the root integration suite, gated on
  `DURABLE_TEMPORAL_ADDRESS`. The replay-tier class waits on #325
  (`refactor/history-source-value-objects`) and ships as a second PR. `TemporalReadThroughEventStore`
  gets no class (#333, #331).
- **Entries**: `tests/integration/Temporal/{FreshNamespace,TemporalJournalEventStoreConformanceTest,TemporalWorkflowRunCatalogConformanceTest}.php`,
  `src/Durable/Testing/WorkflowRunCatalogConformanceTestCase.php` (the `executionIdOf()` hook).
  The replay-tier class and the DUR041 docblock in `EventStoreReplayConformanceTestCase.php` wait
  on #325.
- **State**: in review, PR #502 — dave. Box 2 partial: replay tier waits on #325.
