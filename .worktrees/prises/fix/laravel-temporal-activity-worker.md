# fix/laravel-temporal-activity-worker

- **Scope**: #355. On the temporal backend, the Laravel provider binds a `TemporalActivityWorker` (mirroring Magento's `RuntimeFactory::activityWorker()`), and `durable:temporal-worker --role=activity` drains the activity task queue. The docblocks and both READMEs stop claiming activities ride the Laravel queue.
- **Entries**: `src/DurableLaravel/` (provider, `Console/TemporalWorkerCommand.php`, `config/durable.php`, README), `laravel/README.md`, `tests/unit/DurableLaravel/`.
- **State**: in review — PR #475 (durable-48, lane D).
