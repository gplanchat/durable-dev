# Sourced by every spike script. One restate-server container and one `php -S` per run, both named
# and ported so that they cannot collide with another session's instance on this machine.
# shellcheck shell=bash

IMAGE=docker.restate.dev/restatedev/restate:1.7.12
NAME="durable-restate-spike-$$"
BIND=${SPIKE_BIND:-172.17.0.1}    # docker0: the container reaches `php -S` through host-gateway
PORT=${SPIKE_PORT:-29080}
INGRESS=${SPIKE_INGRESS:-28080}
ADMIN=${SPIKE_ADMIN:-29070}
PHP_PID=

for p in "$PORT" "$INGRESS" "$ADMIN"; do
    if ss -ltn | awk '{print $4}' | grep -q ":$p\$"; then echo "harness: port $p is taken, set SPIKE_PORT/SPIKE_INGRESS/SPIKE_ADMIN" >&2; exit 2; fi
done

cleanup() {
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    if [[ -n "$PHP_PID" ]]; then pkill -P "$PHP_PID" 2>/dev/null || true; kill "$PHP_PID" 2>/dev/null || true; fi
}
trap cleanup EXIT

start() {
    rm -rf var && mkdir var
    PHP_CLI_SERVER_WORKERS=4 php -S "$BIND:$PORT" endpoint.php >var/php.log 2>&1 &
    PHP_PID=$!
    docker run -d --rm --name "$NAME" -p "127.0.0.1:$INGRESS:8080" -p "127.0.0.1:$ADMIN:9070" \
        --add-host host.docker.internal:host-gateway "$IMAGE" >/dev/null
    for _ in $(seq 60); do curl -sf "localhost:$ADMIN/health" >/dev/null && break; sleep 0.5; done
    curl -sf "localhost:$ADMIN/deployments" --json "{\"uri\": \"http://host.docker.internal:$PORT\", \"use_http_11\": true}" >var/register.json \
        || { echo "harness: registration refused" >&2; cat var/php.log >&2; exit 2; }
}

ingress() { curl -sf --max-time 30 "localhost:$INGRESS/$1" "${@:2}"; }

# SQL over the admin API: the server's own view of invocations, not the endpoint's logs.
sql() { curl -sf "localhost:$ADMIN/query" -H 'Accept: application/json' --json "$(jq -n --arg q "$1" '{query: $q}')"; }
