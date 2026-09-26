# feat/run-catalog-workflow-filter

- **Scope**: #558, which also covers #557 and the Temporal id part of #514. Durable writes two
  Keyword search attributes when it starts a workflow on Temporal: `DurableWorkflowName`
  (normalized, `\` → `.`) and `DurableExecutionId`. `listRuns()` filters by workflow name and by
  execution-id prefix on all four catalogues. On Temporal, `findRun($executionId)` queries
  `DurableExecutionId`. Step 0 (Server 1.25.2 + Postgres) is on #558.
- **Slices** (each ≤ 200 lines, TDD):
  - S1: write the two attributes at every Temporal start, with the normalization in one place.
  - S2: `listRuns(workflowName:)` on all four catalogues, taken over from jane's 1c60c18a, with the
    conformance suite covering Temporal.
  - S3: `findRun()` on Temporal by `DurableExecutionId`.
  - S4: #557, `listRuns()` filters by execution-id prefix on all four catalogues.
  - S5: docs (EN+FR) for registering the attributes per namespace, the wait after registering,
    and runs started before the change. Register the attributes in the bench too.
  - The CI registration step (`.github/workflows`) is prepared and handed to the user, never pushed.
- **Entries**: `src/Bridge/Temporal/` (start sites, `Store/TemporalWorkflowRunCatalog.php`),
  `src/Durable/Port/WorkflowRunCatalogInterface.php`, the in-memory, DBAL and Illuminate catalogues,
  `src/Durable/Testing/WorkflowRunCatalogConformanceTestCase.php`, the test doubles, `UPGRADE.md`,
  `documentation/user/`, the bench compose.
- **State**: taken — alice (branch handed over by jane). Reviewers: sirius (code), elsa (user docs).
