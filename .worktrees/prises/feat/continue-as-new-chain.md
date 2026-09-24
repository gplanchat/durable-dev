# feat/continue-as-new-chain

- **Scope**: #322 — continue-as-new keeps the chain: the old and new runs link each other,
  the metadata is superseded rather than deleted, and diagnose prints predecessor / successor.
- **Entries**: `src/Durable/Handler/ResumeWorkflowHandler.php`, `src/Durable/Event/`,
  `JournalRunHistoryReader`, the diagnose command, their tests.
- **State**: in review — PR #473, durable-50 (lane A).
