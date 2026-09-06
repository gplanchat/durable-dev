#!/usr/bin/env bash
# INSTALL CONDITION: always. Weekly, via cron — a weekly cadence outlives the seven-day expiry on
# Claude Code's recurring /loop tasks.
#
# Reads the week's exhaust (failures, demotions, goal violations, spend) and proposes AT MOST 3
# changes: a new constitution law, a skill fix, or a standing goal. Proposals only — it writes to
# STATE.md and never to CLAUDE.md. This is how the constitution evolves from evidence rather than
# from mood. A clean week is reported as clean.
set -euo pipefail
cd "$(dirname "$0")"
SINCE=$(date -d '7 days ago' +%F)
MODEL="${CONDUCTOR_MODEL:-claude-opus-5}"

EXHAUST=$( {
  echo "## failures and alerts"; grep -E "FAILED|ALERT" memory/STATE.md 2>/dev/null | tail -40 || true
  echo "## trust ledger";        cat memory/trust.tsv
  echo "## goal violations";     awk -F'\t' -v s="$SINCE" '$1>=s && $3=="VIOLATED"' memory/goal-ledger.tsv 2>/dev/null || true
  echo "## dispatch";            awk -F'\t' -v s="$SINCE" '$1>=s' memory/dispatch.tsv 2>/dev/null || true
  echo "## spend";               ./scripts/cost-check.sh --report 2>/dev/null || true
} )

OUT=$(printf '%s' "$EXHAUST" | claude -p "You are reviewing one week of an autonomous loop's exhaust.
Propose AT MOST 3 changes, each one of: a new CLAUDE.md law (must carry a number, a never, or a
check command), a fix to a specific skill, or a new standing goal (must come with a shell
predicate). Cite the evidence line for each proposal. If the week is clean, output exactly
'CLEAN WEEK'. Propose only — you are not applying anything." \
  --model "$MODEL" --allowedTools "" --output-format json)

./scripts/log-cost.sh retro "$MODEL" "$OUT"
{ echo; echo "## retro $(date +%F)"; printf '%s' "$OUT" | jq -r '.result'; } >> memory/STATE.md
printf '%s' "$OUT" | jq -r '.result'
