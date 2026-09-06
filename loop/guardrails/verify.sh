#!/usr/bin/env bash
# The gate. The final vote belongs to a deterministic script — the one party in this system that
# cannot be talked into anything. It runs exactly what CI runs (.github/workflows/ci.yml, jobs
# "QA (CS + tests)" and "Analyse statique"), so a tick that passes here passes on the PR.
#
# Exit 0 = the work is done. Nothing else may declare that, per CLAUDE.md.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

# A worker runs in a fresh worktree, and vendor/ is gitignored — so it starts with no tools at
# all. Without this the gate fails 127 on every tick and no work ever reaches a PR. The primary
# checkout's vendor/ is hardlink-copied rather than reinstalled: it costs no disk and no network,
# and composer's path repositories are *relative* symlinks (../../src/Durable/), so they resolve
# against the copy's own worktree. The copy therefore tests this branch's src/, not the primary's.
if [ ! -x vendor/bin/php-cs-fixer ]; then
  PRIMARY=$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')
  if [ -d "$PRIMARY/vendor" ]; then
    echo "── provisioning vendor/ from $PRIMARY (hardlink copy)"
    cp -al "$PRIMARY/vendor" vendor
  else
    echo "── composer install"
    composer install --no-interaction --prefer-dist --no-progress --quiet
  fi
fi

run() { echo "── $1"; shift; "$@"; }

run "PHP-CS-Fixer (dry-run)" composer --quiet cs:check
run "PHPUnit"                composer --quiet test
run "PHPStan"                composer --quiet phpstan
run "Psalm"                  composer --quiet psalm

# The baseline is not allowed to grow. Psalm exits 0 on a suppressed finding, so without this the
# gate would happily green-light a worker that "fixed" static analysis by widening the baseline.
if ! git diff --quiet HEAD -- psalm-baseline.xml; then
  echo "GATE FAIL: psalm-baseline.xml was modified. See CLAUDE.md — fix the code, not the baseline." >&2
  exit 1
fi

echo "── gate: PASS"
