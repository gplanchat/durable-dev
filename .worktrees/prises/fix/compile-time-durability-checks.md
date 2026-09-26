# fix/compile-time-durability-checks

- **Scope**: #259 — with a DBAL journal, refuse at compile time (1) resume and timer messages left
  unrouted or routed to `sync://`, (2) a per-process lock store (`flock`, `semaphore`, `in-memory`,
  `null`) behind `durable.dbal.lock_factory`, with a `durable.dbal.allow_local_lock` opt-out.
  Public service ids untouched (#342 C14).
- **Entries**: `src/DurableBundle/DependencyInjection/Compiler/`, `Configuration.php`,
  `Loader/DbalStores.php`, `DurableBundle.php`, their tests, `sylius/.env` and `sylius/config/`
  (the bench's `LOCK_DSN=flock`).
- **State**: taken — bob. Reviewer: sirius.
