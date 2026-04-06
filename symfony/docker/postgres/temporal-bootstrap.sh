#!/bin/sh
set -eu
export PGPASSWORD="${POSTGRES_PASSWORD:?}"
PGHOST="${PGHOST:-127.0.0.1}"
PGUSER="${POSTGRES_USER:?}"
PGDATABASE="${POSTGRES_DB:?}"

psql() {
  command psql -h "$PGHOST" -U "$PGUSER" -d "$PGDATABASE" "$@"
}

# Rôle + mot de passe (idempotent ; répare un volume créé avant 99-temporal.sql).
psql -v ON_ERROR_STOP=1 <<'SQL'
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'temporal') THEN
    CREATE ROLE temporal LOGIN PASSWORD 'temporal' CREATEDB;
  END IF;
END
$$;
ALTER ROLE temporal WITH PASSWORD 'temporal';
ALTER ROLE temporal CREATEDB;
SQL

exists_db() {
  psql -tAc "SELECT 1 FROM pg_database WHERE datname = '$1'" | grep -q '^1$'
}

if ! exists_db temporal; then
  psql -v ON_ERROR_STOP=1 -c "CREATE DATABASE temporal OWNER temporal"
fi
if ! exists_db temporal_visibility; then
  psql -v ON_ERROR_STOP=1 -c "CREATE DATABASE temporal_visibility OWNER temporal"
fi
