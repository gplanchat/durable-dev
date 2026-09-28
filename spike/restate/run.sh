#!/usr/bin/env bash
# Spike #464: a PHP endpoint in REQUEST_RESPONSE mode against a pinned restate-server.
# Exits 0 only if the activity ran once, the sleep suspended and resumed, and the promise resolved.
set -euo pipefail
cd "$(dirname "$0")"

KEY="spike-$(date +%s)"

source ./lib.sh
start

ingress "Spike/$KEY/run/send" -X POST >/dev/null
sleep 4   # past the 2 s sleep: the workflow is now suspended on the promise
ingress "Spike/$KEY/approve" --json '"yes"' >/dev/null
OUTPUT=$(ingress restate/attach --json "{\"target\": \"workflow\", \"workflowName\": \"Spike\", \"workflowKey\": \"$KEY\"}")

REQUESTS=$(grep -c '^/invoke/Spike/run$' var/requests.log)
echo "output: $OUTPUT"
echo "activity runs: $(wc -l <var/activity.log), run requests: $REQUESTS"

[[ "$OUTPUT" == '{"activity":"charged","approval":"yes"}' ]]
[[ "$(wc -l <var/activity.log)" -eq 1 ]]
[[ "$REQUESTS" -eq 3 ]]   # start, then resumed after the sleep and after the promise
echo "spike: OK"
