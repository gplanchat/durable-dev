#!/bin/sh
# Prêt seulement quand Postgres répond ET que le rôle temporal existe (après bootstrap).
set -eu
pg_isready -h 127.0.0.1 -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" >/dev/null 2>&1 || exit 1
export PGPASSWORD="${POSTGRES_PASSWORD:?}"
psql -h 127.0.0.1 -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -tAc \
  "SELECT 1 FROM pg_roles WHERE rolname = 'temporal'" | grep -q '^1$'
