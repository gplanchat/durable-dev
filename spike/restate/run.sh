#!/usr/bin/env bash
# Spike #464: a PHP endpoint in REQUEST_RESPONSE mode against a pinned restate-server.
# Exits 0 only if the activity ran once, the sleep suspended and resumed, and the promise resolved.
set -euo pipefail
cd "$(dirname "$0")"

IMAGE=docker.restate.dev/restatedev/restate:1.7.12
NAME=durable-restate-spike
PORT=9080
KEY="spike-$(date +%s)"

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; pkill -f "^php -S 127.0.0.1:$PORT" || true; }   # php -S workers outlive their parent
trap cleanup EXIT

rm -rf var && mkdir var
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" endpoint.php >var/php.log 2>&1 &
docker run -d --rm --name "$NAME" --network host "$IMAGE" >/dev/null

until curl -sf localhost:9070/health >/dev/null; do sleep 0.5; done
curl -sf localhost:9070/deployments --json "{\"uri\": \"http://127.0.0.1:$PORT\", \"use_http_11\": true}" >var/register.json

curl -sf -X POST "localhost:8080/Spike/$KEY/run/send" >/dev/null
sleep 4   # past the 2 s sleep: the workflow is now suspended on the promise
curl -sf "localhost:8080/Spike/$KEY/approve" --json '"yes"' >/dev/null
OUTPUT=$(curl -sf --max-time 20 localhost:8080/restate/attach --json "{\"target\": \"workflow\", \"workflowName\": \"Spike\", \"workflowKey\": \"$KEY\"}")

REQUESTS=$(grep -c '^/invoke/Spike/run$' var/requests.log)
echo "output: $OUTPUT"
echo "activity runs: $(wc -l <var/activity.log), run requests: $REQUESTS"

[[ "$OUTPUT" == '{"activity":"charged","approval":"yes"}' ]]
[[ "$(wc -l <var/activity.log)" -eq 1 ]]
[[ "$REQUESTS" -eq 3 ]]   # start, then resumed after the sleep and after the promise
echo "spike: OK"
