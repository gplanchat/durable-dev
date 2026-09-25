# test/temporal-heartbeat-on-every-host

- **Scope**: #518 — a Laravel-hosted and a Magento-factory-hosted Temporal activity worker in the
  root integration suite, the heartbeat-outlives-its-timeout test and the cancellation test on both,
  red once with the no-op sender (a switch in the test harness only, never in `src/`).
- **Entries**: `tests/integration/Temporal/` (the worker scripts, `Fixtures/`,
  `TemporalServerTestCase.php` for an overridable activity role, a new test class).
- **State**: in progress — dave.
