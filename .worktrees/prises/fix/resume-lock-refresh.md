# fix/resume-lock-refresh

- **Scope**: #340, R-8 — `SingleResumeLockMiddleware` refreshes the resume locks it holds at each
  step boundary, meaning every message that crosses the bus while a pass holds the lock (a
  `sync://` activity, a nested resume). `lock_ttl` then bounds one step, not a whole pass. The
  guard and the `lock_ttl` node (B-1, #254) were done in #468.
- **Entries**: `src/Bridge/Dbal/Messenger/SingleResumeLockMiddleware.php`, its test, the
  `lock_ttl` row of `documentation/user/configuration/_index{,.fr}.md` and the `lock_ttl` info
  line of `src/DurableBundle/DependencyInjection/Configuration.php` (#499 edits the line two above).
- **State**: in review — PR #503, vera; reviewer dave.
