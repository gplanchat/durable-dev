# docs/core-comments-in-english

- **Work item**: WA006 says English everywhere, and the comments are where the French still lives —
  roughly 5 600 accented lines across the tree. This slice takes the first package: `src/Durable`,
  the core. Comments and docblocks only; the code, the strings and the identifiers are already
  English there.
- **Entry points**: `src/Durable/**`, except `src/Durable/Testing/**` — that directory is being
  edited by `fix/ui-strings-in-english` (PR #287) and comes in its own slice once that merges.
  The other packages (`src/Bridge/*`, `src/DurableBundle`, `src/DurableModule`, `tests/`, the four
  benches) follow, one package per slice.
- **State**: in progress.
