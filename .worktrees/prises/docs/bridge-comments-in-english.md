# docs/bridge-comments-in-english

- **Work item**: the second comment slice, after `docs/core-comments-in-english` (PR #292) took
  `src/Durable`. This one takes the three bridges — the packages an integrator reads to learn what
  a backend has to do.
- **Entry points**: `src/Bridge/Temporal/**` (except `Api/` and `Generated/`, which are generated
  from protobuf and supervised), `src/Bridge/Dbal/**`, `src/Bridge/Illuminate/**`. Comments and
  docblocks only; identifiers and string literals stay as they are unless a file has no other
  French left.
- **State**: in progress.
