# fix/table-queue-contention-test

- **Scope**: #731, #738. The two-workers table queue test makes the contention deterministic (a start barrier between the worker processes).
- **Entries**: tests/integration/DurableModule/TableQueue/.
- **Overlap**: none known.
- **Session**: durable-01 (worker), 2026-10-09.
- **State**: in progress.
