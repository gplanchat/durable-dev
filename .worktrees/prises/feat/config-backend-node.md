# feat/config-backend-node

- **Scope**: #334 — a `backend:` node with the old keys derived and deprecated, cross-node
  validation with the config path, per-key checks (DSN, retries, contract interfaces),
  `activity_transport.table_name` deprecated, a `profiler.enabled` node.
- **Entries**: `src/DurableBundle/DependencyInjection/`, its tests,
  `documentation/user/configuration/`, `UPGRADE.md`.
- **State**: in progress — durable-7f (lane B).
