#!/usr/bin/env bash
# log-cost.sh <stage> <model> <claude-json>
#
# Appends one line to memory/usage.tsv per model call.
#
# The article parses the API usage block and estimates dollars from token counts. The claude CLI
# reports total_cost_usd itself, so this records the real figure. thinking_tokens is still logged
# explicitly: hidden reasoning bills as output and is where surprise spend lives — CHECK 6 is that
# this column is nonzero. cache_read is logged because it is the only proof the prompt ordering in
# loop.sh is still earning the caching discount.
set -euo pipefail
cd "$(dirname "$0")/.."
USAGE=memory/usage.tsv
[ -f "$USAGE" ] || printf 'date\tstage\tmodel\tinput\toutput\tthinking\tcache_read\tcost_usd\n' > "$USAGE"

printf '%s' "${3:-{}}" | jq -r --arg d "$(date +%F)" --arg s "$1" --arg m "$2" '
  [$d, $s, $m,
   (.usage.input_tokens // 0),
   (.usage.output_tokens // 0),
   (.usage.output_tokens_details.thinking_tokens // 0),
   (.usage.cache_read_input_tokens // 0),
   (.total_cost_usd // 0)] | @tsv' >> "$USAGE"
