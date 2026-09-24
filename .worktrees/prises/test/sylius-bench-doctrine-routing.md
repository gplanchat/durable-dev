# test/sylius-bench-doctrine-routing

- **Scope**: #361, part 1 of 3. The Sylius bench routes the five durable messages to `doctrine://default`, and a functional test starts a test-only workflow with a timer and drains it with `durable:worker`. The CI step runs `tests/Functional`, approved by the owner on 2026-09-24.
- **Entries**: `sylius/config/packages/messenger.yaml`, `sylius/tests/Functional/`, `sylius/config/` (test-only wiring), `.github/workflows/ci.yml` (the Sylius job's test step).
- **State**: in review — PR #481 (durable-48, lane D).
