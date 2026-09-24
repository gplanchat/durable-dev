# chore/dead-code-core

- **Scope**: #371 — delete the internal helpers with no caller in `src/Durable`; document the
  public ones instead (`wrap()`, `hasSignalHandler()`, `hasUpdateHandler()`, the fluent timeout
  setters, `withTaskQueue()`), add a heartbeat example; `toWireMetadata()` stays (DUR031).
  Decisions by the user on 2026-09-24.
- **Entries**: `src/Durable/`, `documentation/user/{activities,options,workflows}/`, `UPGRADE.md`,
  their tests.
- **State**: in progress — durable-50 (lane A).
