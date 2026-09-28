#!/usr/bin/env bash
# Spike #641, scenario 2: with Restate's retries switched off on a handler (manifest v5,
# retryPolicyMaxAttempts: 1, KILL), does a failure reach the calling workflow after one attempt, and
# does a PHP worker killed mid-activity reach it as a failure? Prints the observation; exits non-zero
# only when the harness failed.
set -euo pipefail
cd "$(dirname "$0")"
source ./lib.sh
start

KEY="retry-$$"
ingress "Act/flakyDefault/send" --json '"D"' >/dev/null   # control, no retry fields in the manifest
ingress "Retry/$KEY/run/send" -X POST >/dev/null
OUTPUT=$(ingress restate/attach --max-time 90 --json "{\"target\": \"workflow\", \"workflowName\": \"Retry\", \"workflowKey\": \"$KEY\"}") \
    || OUTPUT="(no result within 90 s)"

echo "manifest versions offered: $(head -1 var/discover.log)"
echo "workflow saw: $OUTPUT"
echo "attempts: flaky=$(grep -c '^flaky$' var/attempts.log || true), crash=$(grep -c '^crash$' var/attempts.log || true), control flakyDefault=$(grep -c '^flakyDefault$' var/attempts.log || true) by now"
sql "SELECT target_handler_name AS handler, status, completion_result AS result, completion_failure AS failure
     FROM sys_invocation WHERE target_service_name = 'Act'" \
    | jq -r '.rows[] | "server: \(.handler)\t\(.status)\t\(.result // "-")\t\(.failure // "-")"'
