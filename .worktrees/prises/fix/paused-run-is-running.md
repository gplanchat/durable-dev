# fix/paused-run-is-running

- **Scope**: #506 — a `PAUSED` Temporal run is listed as `Running`, not `Failed`; `UNSPECIFIED` (and
  any status the table does not know) is decided by close time: none is `Running`, one is `Failed`.
  No new `WorkflowRunStatus` case. The "paused by an operator" wait reason is a follow-up after #476.
- **Entries**: `src/Bridge/Temporal/Store/TemporalWorkflowRunCatalog.php`,
  `tests/unit/Bridge/Temporal/TemporalWorkflowRunCatalogTest.php`.
- **Done when**: unit tests on `statusOf()`'s input pin PAUSED → Running, UNSPECIFIED open → Running,
  UNSPECIFIED closed → Failed; #504's invariant test stays green; verify.sh green.
- **State**: in review — PR #522, bob, reviewer dave.
