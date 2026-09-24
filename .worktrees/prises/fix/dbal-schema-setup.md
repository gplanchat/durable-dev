# fix/dbal-schema-setup

- **Scope**: #339 — `durable:setup` creates the tables on the configured connection; the schema
  listener honours the schema assets filter; both run catalogues read history from the configured
  events table; a composite `(status, started_at)` index on both SQL bridges; a DI test for the
  `doctrine.event_listener` tag; `auto_setup` on MySQL no longer runs DDL inside the caller's
  transaction ("There is no active transaction", seen in #481).
- **Entries**: `src/Bridge/Dbal/`, `src/Bridge/Illuminate/` (catalogue, schema, migration),
  `src/DurableBundle/` (command, schema listener, extension wiring), `src/DurableLaravel/` wiring,
  their tests and the configuration docs.
- **State**: in review — PR #499, durable-7f (lane B).
