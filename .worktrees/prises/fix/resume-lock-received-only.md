# fix/resume-lock-received-only

- **Scope**: #254, refs #340 — the DBAL resume lock no longer makes a worker wait for its own
  lock; `durable.dbal.lock_ttl` replaces the hard-coded 300 s TTL. The TTL-refresh item of #340
  stays open.
- **Entries**: `src/Bridge/Dbal/Messenger/SingleResumeLockMiddleware.php`,
  `src/DurableBundle/DependencyInjection/`, `documentation/user/configuration/`, their tests.
- **State**: in review — PR #468, taken over by durable-7f (lane B).
