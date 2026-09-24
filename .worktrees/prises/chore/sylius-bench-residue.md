# chore/sylius-bench-residue

- **Scope**: #361, part 2 of 3. Delete the Sylius-Standard files that can never run in this bench (nested `.github/`, `.platform*`, `.upsun/`, `compose*.yml`, `Makefile`, `ecs.php`, `rector.php`, `CONFLICTS.md`) and make `sylius/README.md` the bench's own page. Over 200 lines, deletions only: approved by the owner on 2026-09-24.
- **Entries**: `sylius/` (residue files, `README.md`).
- **State**: in review — PR #480 (durable-48, lane D). `compose.yml` and `compose.override.dist.yml` are kept: the root README boots the shop with them.
