# test/temporal-conformance

- **Scope**: #326 box 2, what is left: the replay-tier conformance class for
  `TemporalExecutionHistory`, plus the DUR041 docblock in `EventStoreReplayConformanceTestCase.php`
  that still says no Temporal store extends a tier. The event store and catalog classes merged
  with #502.
- **Entries**: a new `tests/integration/Temporal/` replay conformance class,
  `src/Durable/Testing/EventStoreReplayConformanceTestCase.php` (docblock).
- **State**: waiting on #325 (`refactor/history-source-value-objects`), which retypes
  `WorkflowHistorySourceInterface`. No branch yet — dave.
