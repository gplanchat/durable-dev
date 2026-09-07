#!/usr/bin/env bash
# cost-check.sh --budget   sum today's spend; exit 3 over the line (loop.sh turns that into its
#                          own exit 3, so a breached budget stops the tick before any model call)
# cost-check.sh --report   7-day spend by stage, plus the cache-hit ratio CHECK 6 asks for
#
# The dumb, final mechanism. It cannot be reasoned with, which is the entire point: prompt
# caching and task budgets both depend on the model behaving, and this does not.
set -euo pipefail
cd "$(dirname "$0")/.."
USAGE=memory/usage.tsv
BUDGET="${LOOP_DAILY_BUDGET_USD:-5.00}"
[ -f "$USAGE" ] || exit 0

case "${1:---budget}" in
  --budget)
    today=$(awk -F'\t' -v d="$(date +%F)" '$1==d{s+=$8} END{printf "%.4f", s+0}' "$USAGE")
    if awk -v t="$today" -v b="$BUDGET" 'BEGIN{exit !(t>=b)}'; then
      echo "$(date +%F) ALERT budget breached: \$$today of \$$BUDGET" >> memory/STATE.md
      echo "budget breached: \$$today of \$$BUDGET" >&2
      exit 3
    fi
    ;;
  --report)
    since=$(date -d '7 days ago' +%F)
    echo "spend by stage since $since"
    awk -F'\t' -v s="$since" 'NR>1 && $1>=s {c[$2]+=$8; t+=$8}
      END{for(k in c) printf "  %-10s $%.4f\n", k, c[k]; printf "  %-10s $%.4f\n", "TOTAL", t+0}' "$USAGE"
    awk -F'\t' -v s="$since" 'NR>1 && $1>=s {i+=$4; r+=$7}
      END{if(i+r>0) printf "cache reads: %.0f%% of conductor-class input (want: most of it)\n", 100*r/(i+r)}' "$USAGE"
    awk -F'\t' -v s="$since" 'NR>1 && $1>=s {th+=$6}
      END{printf "thinking tokens: %d (zero means the usage parsing is broken)\n", th+0}' "$USAGE"
    ;;
esac
