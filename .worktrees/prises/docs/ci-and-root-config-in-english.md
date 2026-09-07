# docs/ci-and-root-config-in-english

- **Work item**: the CI workflows and the root tool configuration — the last French left outside
  `documentation/`, `.cursor/` and the supervised coordination registry. Comments, step names and
  the job names that are not load-bearing.
- **⚠ Supervised scope, authorised by a human.** CLAUDE.md lists `.github/workflows/` as never
  touched unattended because CI reaches outside this repo. This slice was asked for explicitly.
- **⚠ One job name must not move.** `Analyse statique (PHPStan + Psalm)` in `ci.yml` is a required
  status check on `main` (with the four `QA (CS + tests)` contexts). Renaming it makes the required
  context never report, and every PR becomes unmergeable until branch protection is updated. It
  stays French, with a comment saying why; renaming it is a two-step a human triggers.
- **Entry points**: `.github/workflows/*.yml`, `docker/php-grpc/**` (the subject of one of those
  workflows), `.php-cs-fixer.dist.php`, `psalm-magento.xml`, `phpstan.neon`,
  `phpstan-magento.neon`, `.gitignore`, `.git-blame-ignore-revs`, one line of `README.md`.
- **Not in scope**: `UPGRADE.md` (128 French lines — a migration guide, not configuration),
  `bin/splitsh-publish.sh` and `bin/prises-check*.sh` (supervised, and not what was asked for),
  `openspec/` (point-in-time records), `documentation/` (WA006's own exception).
- **State**: in progress.
