# fix/core-hygiene-batch

- **Scope**: #329 — the core hygiene batch: swallowed store errors on loser cancellation, empty
  ExecutionId, error_log, #[\Override] on 8.2, orphan docblocks, dead {@see}, inline child
  workflowType, the non-fiber await branch, dead initialisers.
- **Entries**: `src/Durable/` (the files the ticket lists), their tests.
- **State**: in progress — durable-50 (lane A).
