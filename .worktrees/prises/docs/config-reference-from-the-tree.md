# docs/config-reference-from-the-tree

- **Scope**: #365, and #334's box 2: the reference block of `documentation/user/configuration/`
  (EN, FR) is `config:dump-reference durable` run in the Symfony bench, between markers; a bench test
  fails when the page and the tree drift (block equal to the dump, every key documented and none
  that the tree lacks); `dbal.auto_setup` documented and the `max_activity_retries` gloss fixed.
  Stacked on #485 (branch `docs/config-reference-from-the-tree` from a59fd6d6); PR held until it
  lands. Host table: #357, out of scope.
- **Entries**: `documentation/user/configuration/_index.md`, `documentation/user/configuration/_index.fr.md`,
  `symfony/tests/Unit/ConfigurationReferenceTest.php` (new), `info()` strings only in
  `src/DurableBundle/DependencyInjection/Configuration.php` (after #485).
- **Done when**: the bench test is red on a hand edit of the block and green on the page;
  `auto_setup` appears on both pages; verify.sh green.
- **State**: in progress, stacked on #485 — bob.
