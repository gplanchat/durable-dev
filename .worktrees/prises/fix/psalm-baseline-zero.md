# fix/psalm-baseline-zero

- **Work item**: bring `psalm-baseline.xml` to zero entries — fix the 16 live findings, drop the 59 dead `MissingOverrideAttribute` entries (already suppressed in `psalm.xml`) and the stale ones.
- **Entries**: `psalm-baseline.xml`, `psalm.xml`, a Psalm stub for the grpc extension and the optional Doctrine ORM / illuminate/cache classes, one-line type fixes across `src/`.
- **State**: in review — PR #433
