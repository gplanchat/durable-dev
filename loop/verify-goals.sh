#!/usr/bin/env bash
# The daily sentinel. Finished work never closes here: it becomes an invariant re-checked every
# day, forever. This script FINDS, it never REPAIRS — detection and repair stay separate parties,
# and a violation is a page, not a work order the sentinel hands itself.
#
# Exit 1 if anything is violated.
set -euo pipefail
cd "$(dirname "$0")"
REPO=$(git rev-parse --show-toplevel)
LEDGER=memory/goal-ledger.tsv
[ -f "$LEDGER" ] || printf 'date\tgoal\tresult\n' > "$LEDGER"

violated=0
shopt -s nullglob
for goal in goals/*.md; do
  name=$(basename "$goal" .md)
  [ "$name" = "README" ] && continue
  status=$(awk -F': *' '/^status:/{print $2; exit}' "$goal")
  [ "$status" = "retired" ] && continue

  predicate=$(awk '/^predicate: /{sub(/^predicate: /,""); print; exit}' "$goal")
  if [ -z "$predicate" ]; then
    echo "SKIP $name: no predicate" >&2
    continue
  fi

  if ( cd "$REPO" && eval "$predicate" ) >/dev/null 2>&1; then
    printf '%s\t%s\t%s\n' "$(date +%F)" "$name" pass >> "$LEDGER"
    sed -i "s/^status: .*/status: satisfied/; s/^last-pass: .*/last-pass: $(date +%F)/" "$goal"
    echo "PASS $name"
  else
    printf '%s\t%s\t%s\n' "$(date +%F)" "$name" VIOLATED >> "$LEDGER"
    sed -i "s/^status: .*/status: VIOLATED/" "$goal"
    echo "$(date +%F) ALERT goal VIOLATED: $name" >> memory/STATE.md
    echo "VIOLATED $name" >&2
    violated=1
  fi
done

exit $violated
