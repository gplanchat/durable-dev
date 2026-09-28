#!/usr/bin/env bash
# Spike #641, scenario 3: what request/response mode costs as a journal grows. A workflow of N steps,
# each a 1 KB activity result then a suspension; prints the request size and the PHP time per round
# trip. Exits non-zero only when the harness failed.
set -euo pipefail
cd "$(dirname "$0")"
source ./lib.sh
start

export LC_ALL=C   # awk reads "0.123" as 0 under a comma-decimal locale
STEPS=${1:-100}
KEY="size-$$"
STARTED=$(date +%s%N)
ingress "Size/$KEY/run/send" --json "$STEPS" >/dev/null
ingress restate/attach --max-time 120 --json "{\"target\": \"workflow\", \"workflowName\": \"Size\", \"workflowKey\": \"$KEY\"}" >/dev/null
WALL_MS=$(( ($(date +%s%N) - STARTED) / 1000000 ))

echo "round trip  request bytes  PHP ms"
awk -v n="$STEPS" 'NR == 1 || NR == 10 || NR == int(n / 2) || NR == n { printf "%10d  %13d  %6.2f\n", NR, $1, $2 }' var/size.log
awk '{ bytes += $1; ms += $2 } END { printf "total: %d round trips, %.1f MB sent to PHP, %.0f ms in PHP\n", NR, bytes / 1048576, ms }' var/size.log
echo "wall clock, start to result: ${WALL_MS} ms"
