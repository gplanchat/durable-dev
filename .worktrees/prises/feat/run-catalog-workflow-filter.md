# feat/run-catalog-workflow-filter

- **Scope**: #558, which also covers #557 and the Temporal id part of #514. Durable writes two
  Keyword search attributes when it starts a workflow on Temporal: `DurableWorkflowName`
  (normalized, `\` → `.`) and `DurableExecutionId`. `listRuns()` filters by workflow name and by
  execution-id prefix on all four catalogues. On Temporal, `findRun($executionId)` queries
  `DurableExecutionId`. Step 0 (Server 1.25.2 + Postgres) is on #558.
- **Slices** (each ≤ 200 lines per commit, TDD):
  - S1, PR #562 (branch `feat/temporal-durable-search-attributes`): writes the two attributes at
    every Temporal start (top-level, child, continue-as-new, Nexus), with the normalization in one
    place, plus docs (EN+FR), UPGRADE and bench registration.
  - S2 (branch `feat/run-catalog-search-attributes`, from jane's 1c60c18a): `listRuns()` filters by
    workflow name (#558) and by execution-id prefix (#557) on all four catalogues, with the
    conformance suite covering Temporal. Opens once #562 lands.
  - The CI registration step (`.github/workflows`) is prepared and handed to the user, never pushed.
  - `findRun()` and the Temporal run identity moved to jane (#514).
- **Entries**: `src/Bridge/Temporal/` (start sites, `Store/TemporalWorkflowRunCatalog.php`),
  `src/Durable/Port/WorkflowRunCatalogInterface.php`, the in-memory, DBAL and Illuminate catalogues,
  `src/Durable/Testing/WorkflowRunCatalogConformanceTestCase.php`, the test doubles, `UPGRADE.md`,
  `documentation/user/`, the bench compose.
- **State**: S1 in review — PR #562, alice. Reviewers: sirius (code), elsa (user docs).
