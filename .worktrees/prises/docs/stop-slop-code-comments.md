# docs/stop-slop-code-comments

- **Work**: the second half of the stop-slop pass. The first (PR #286, merged) took
  `documentation/user/` and everything under `hugo-docs/`. This one takes `CLAUDE.md`,
  `documentation/wa/` and the comments in `src/`.
- **Scope**: `CLAUDE.md` (9), `documentation/wa/*.md` (58) and 511 dashes in `src/` comments across
  179 files. Comments only in `src/`: string literals keep theirs, and that is deliberate.
- **Excluded**: `documentation/adr/` (supervised-only, the constitution forbids it),
  `src/Bridge/Temporal/Api/` and `src/Bridge/Temporal/Generated/` (generated from protobuf).
- **Careful**: `src/` was split into five batches, each under the 200-line commit limit. The
  `Billing/` and `Delivery/` contracts arrived from another branch mid-pass and needed a sixth
  commit. Two survivors are on purpose, both verbatim quotations: the nowdoc fixture in
  `UnmigratableTemporalCallRector` reproduces what the rule writes into user code, and it is built
  from the `REASONS` constant.
- **State**: in review.
