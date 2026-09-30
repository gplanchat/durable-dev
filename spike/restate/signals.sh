#!/usr/bin/env bash
# Spike #641, scenario 4: can named signals carry Durable's signals and updates? Several signals that
# share one name, sent from one journal and from concurrent senders: are all delivered, in which order?
# And an update round trip: the sender hands out an awakeable, the workflow completes it.
# Prints the observations; exits non-zero only when the harness failed.
set -euo pipefail
cd "$(dirname "$0")"
source ./lib.sh
start

workflow() { ingress "Sig/$1/run/send" --json "$2" | jq -r .invocationId; }
result() {
    ingress restate/attach --max-time 20 --json "{\"target\": \"workflow\", \"workflowName\": \"Sig\", \"workflowKey\": \"$1\"}" \
        || { echo "(no result within 20 s) journal:"; sql "SELECT index, entry_type, name FROM sys_journal WHERE id = '$2' ORDER BY index" | jq -c '.rows[]'; }
}

WF=$(workflow "burst-$$" 5)
ingress Sender/burst/send --json "{\"target\": \"$WF\", \"n\": 5}" >/dev/null
echo "one journal, 5 signals sent in order 1..5: $(result "burst-$$" "$WF")"

WF=$(workflow "concurrent-$$" 5)
SENDERS=()
for v in 1 2 3 4 5; do ingress Sender/one/send --json "{\"target\": \"$WF\", \"v\": $v}" >/dev/null & SENDERS+=($!); done
wait "${SENDERS[@]}"   # a bare wait would also wait for php -S
echo "5 concurrent senders: $(result "concurrent-$$" "$WF")"

WF=$(workflow "update-$$" 1)
echo "update, the sender received: $(ingress Sender/update --json "{\"target\": \"$WF\", \"v\": 42}")"
echo "update, the workflow returned: $(result "update-$$" "$WF")"
