# fix/temporal-failed-filter

- **Scope**: #504 — `TemporalWorkflowRunCatalog`'s `Failed` filter matches every server status the
  listing reports as `Failed` (TERMINATED, TIMED_OUT). No change to `WorkflowRunStatus`. Stacked
  on `test/temporal-conformance` (#502) for its `FreshNamespace` helper.
- **Entries**: `src/Bridge/Temporal/Store/TemporalWorkflowRunCatalog.php`, a new test under
  `tests/integration/Temporal/TemporalRunCatalogFailedFilterTest.php`.
- **State**: branch pushed (f21cc7e1), PR held: epic #305 queue full — dave.
