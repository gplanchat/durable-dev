# chore/dead-code-bundle-plugin-slice-2

- **Scope**: #373 slice 2 — unused `$config` of `registerRuntime()`/`registerEngine()` (5a); the
  catalogue fakes in tests replaced by `InMemoryWorkflowRunCatalog` (6); the stale `ponytail:`
  marker in the Illuminate schema (8). 5c checked: not a defect (the tree is balanced, the
  configuration reference test pins `workflow_metadata` at the top level).
- **Entries**: `src/DurableBundle/DependencyInjection/DurableExtension.php`,
  `src/Bridge/Illuminate/Schema/DurableSchema.php`, `tests/unit/Durable/Observation/RunDashboardTest.php`,
  `src/DurablePlugin/tests/Integration/TheDashboardRendersARunHistoryTest.php`,
  `src/DurablePlugin/tests/Unit/ThePreviousPageLinkTest.php`.
- **State**: in review, PR #535 — dave.
