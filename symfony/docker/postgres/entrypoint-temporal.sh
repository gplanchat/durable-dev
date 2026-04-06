#!/bin/sh
# Enveloppe autour de l’entrypoint officiel Postgres : après démarrage, applique
# temporal-bootstrap.sh (idempotent) pour les volumes créés avant 99-temporal.sql.
set -eu

/usr/local/bin/docker-entrypoint.sh postgres &
pid=$!

i=0
while ! pg_isready -h 127.0.0.1 -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -gt 120 ]; then
    echo "postgres did not become ready in time" >&2
    exit 1
  fi
  sleep 0.5
done

export PGPASSWORD="${POSTGRES_PASSWORD:?}"
export PGHOST=127.0.0.1
/usr/local/bin/temporal-bootstrap.sh

trap 'kill -TERM "$pid" 2>/dev/null; wait "$pid" 2>/dev/null' TERM INT
wait "$pid"
