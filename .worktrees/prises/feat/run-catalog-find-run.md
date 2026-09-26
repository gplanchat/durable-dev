# feat/run-catalog-find-run

- **Scope**: #264 and #383, as stacked slices, each with its own PR.
  - S1: `findRun()` on `WorkflowRunCatalogInterface`, implemented on every catalog, with the
    conformance suite and an UPGRADE note.
  - S2: `RunDashboard::listing()` and `run()`.
  - S3: the plugin's list route and run route. `/dashboard` redirects to them.
  - S4: the profiler panel shows `waitingOn` through `findRun()`. The public service ids are left
    alone, since they are C14 of the extension-loaders prise.
  - S5: `listRuns()` filters by workflow name and by run id (#264 point 3). If this outgrows one
    slice, it moves to its own issue.
- **Entries**:
  - `src/Durable/Port/WorkflowRunCatalogInterface.php` and the four catalogs;
  - `src/Durable/Testing/WorkflowRunCatalogConformanceTestCase.php`;
  - `src/Durable/Observation/RunDashboard.php`;
  - `src/DurablePlugin/`, `sylius/` tests;
  - `src/DurableBundle/DataCollector/`;
  - `UPGRADE.md`.
- **Not in scope**:
  - #383 slice B, the grid data provider. It waits on the cursor-vs-Pagerfanta decision and on a
    dependency, both the user's.
  - The Temporal run id and `waitingOn`, which belong to #514.
- **State**: S1 in progress — jane. Reviewer: jack.
