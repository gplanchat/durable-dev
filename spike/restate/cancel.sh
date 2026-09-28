#!/usr/bin/env bash
# Spike #641, scenario 1: who propagates a native cancellation to the calls a workflow has in flight?
# Prints what the server reports for the workflow and its two calls; exits non-zero only when the
# harness failed. It asserts no expected answer: the question is open.
set -euo pipefail
cd "$(dirname "$0")"
source ./lib.sh
start

for mode in none bridge; do
    KEY="cancel-$mode-$$"
    WF=$(ingress "Cancel/$KEY/run/send" --json "\"$mode\"" | jq -r .invocationId)
    sleep 1.5   # A and B are now running their 6 s sleep, the workflow waits on B
    curl -sf -X PATCH "localhost:$ADMIN/invocations/$WF/cancel" >/dev/null \
        || curl -sf -X DELETE "localhost:$ADMIN/invocations/$WF?mode=Cancel" >/dev/null \
        || { echo "harness: the admin API refused the cancellation" >&2; exit 2; }
    sleep 8     # past the 6 s sleep: every call has settled one way or the other

    echo "== mode $mode (workflow $WF)"
    sql "SELECT target_handler_name AS handler, status, completion_result AS result, completion_failure AS failure
         FROM sys_invocation WHERE id = '$WF' OR invoked_by_id = '$WF' ORDER BY target_service_name DESC, target_handler_name" \
        | jq -r '.rows[] | "server: \(.handler)\t\(.status)\t\(.result // "-")\t\(.failure // "-")"'
done
echo "== endpoint log (second source)"
cat var/act.log
