# feat/run-catalog-find-run

- **Scope**: #264 and #383, as stacked slices, each with its own PR.
  - S1: `findRun()` on `WorkflowRunCatalogInterface`, implemented on every catalog, with the
    conformance suite and an UPGRADE note.
  - S2: `RunDashboard::listing()` and `run()`.
  - S3: the plugin's list route and run route. `/dashboard` redirects to them.
  - S4: the profiler panel shows `waitingOn` through `findRun()`. The public service ids are left
    alone, since they are C14 of the extension-loaders prise.
  - S5: `listRuns()` filters by workflow name (#264 point 3). The run-id fragment filter moved to
    #557, which depends on #514.
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
- **State** (jane; reviewer jack; all four PRs approved):
  - S1 #552, base `main`.
  - S2 #553, stacked on #552.
  - S3 #555, stacked on #553. anna adds the screenshots after #551.
  - S4 #556, stacked on #552.
  - Merged in order as CI goes green. S5 starts once #552 has merged.
