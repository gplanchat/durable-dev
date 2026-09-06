#!/usr/bin/env bash
# The trust ledger. Autonomy is granted per skill, from logged evidence, never globally.
#
#   trust-log.sh <skill> pass|fail   record a run
#   trust-log.sh --tier <skill>      print the skill's tier
#   trust-log.sh --render            print the whole ledger
#
# memory/trust.tsv columns: skill  runs  passes  tier
#
# TIERS. Only the top row is the article's: auto requires 20 runs at 95% verified. The tier table
# in the source is a published image, so the middle thresholds below are this repository's choice,
# not a transcription — they are deliberately conservative and meant to be re-tuned from the
# ledger itself once it holds a month of data.
#
#   auto   runs >= 20 and rate >= 95%   runs unattended, may open a PR
#   watch  runs >=  5 and rate >= 80%   runs only with a human watching; the loop queues it
#   queue  everything else              never runs unattended
set -euo pipefail
cd "$(dirname "$0")/.."
LEDGER=memory/trust.tsv
[ -f "$LEDGER" ] || printf 'skill\truns\tpasses\ttier\n' > "$LEDGER"

tier_of() { # runs passes -> tier
  local runs=$1 passes=$2
  [ "$runs" -eq 0 ] && { echo queue; return; }
  local rate=$(( passes * 100 / runs ))
  if   [ "$runs" -ge 20 ] && [ "$rate" -ge 95 ]; then echo auto
  elif [ "$runs" -ge  5 ] && [ "$rate" -ge 80 ]; then echo watch
  else echo queue; fi
}

case "${1:-}" in
  --render) column -t -s "$(printf '\t')" "$LEDGER"; exit 0 ;;
  --tier)
    skill=$2
    awk -F'\t' -v s="$skill" '$1==s{print $4; found=1} END{if(!found) print "queue"}' "$LEDGER"
    exit 0 ;;
esac

skill=$1; outcome=$2
runs=$(awk -F'\t' -v s="$skill" '$1==s{print $2}' "$LEDGER"); runs=${runs:-0}
passes=$(awk -F'\t' -v s="$skill" '$1==s{print $3}' "$LEDGER"); passes=${passes:-0}
was=$(awk -F'\t' -v s="$skill" '$1==s{print $4}' "$LEDGER"); was=${was:-queue}

runs=$((runs + 1))
[ "$outcome" = "pass" ] && passes=$((passes + 1))
now=$(tier_of "$runs" "$passes")

tmp=$(mktemp)
awk -F'\t' -v s="$skill" '$1!=s' "$LEDGER" > "$tmp"
printf '%s\t%s\t%s\t%s\n' "$skill" "$runs" "$passes" "$now" >> "$tmp"
mv "$tmp" "$LEDGER"

# Demotion is automatic and loud. The contract classifies this line as a page.
rank() { case $1 in auto) echo 3;; watch) echo 2;; *) echo 1;; esac; }
if [ "$(rank "$now")" -lt "$(rank "$was")" ]; then
  echo "$(date +%F) ALERT demoted: $skill $was -> $now ($passes/$runs)" >> memory/STATE.md
fi
echo "$now"
