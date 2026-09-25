# feat/await-label

- **Scope**: #324 option A — an optional `label` on `WorkflowEnvironment::await()` for a condition; the
  run list shows it (`waiting on <label>`) instead of `condition at <file>:<line>`. A label on a
  non-Closure awaitable throws. Display-only: nothing enters the journal.
- **Entries**: `src/Durable/WorkflowEnvironment.php` (`await()`), `src/Durable/Awaitable/ConditionAwaitable.php`,
  their tests, `documentation/user/workflows/` and `documentation/user/dashboard/` (EN, FR), one
  `UPGRADE.md` line.
- **State**: in review — PR #540, antoine. Reviewers: dave, vera (rule 3).
