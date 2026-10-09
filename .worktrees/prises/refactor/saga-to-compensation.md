# refactor/saga-to-compensation

- **Scope**: step 1 of the ubiquitous-language change (#993, epic #990): the `Durable\Workflow\Saga` helper becomes `Compensation`, with a Rector entry in the upgrade set and an UPGRADE section.
- **Entries**: `src/Durable/Workflow/Saga.php`, `tests/unit/Durable/Workflow/SagaTest.php`, `src/DurableRector/config/sets/durable-upgrade.php`, `src/DurableRector/Rector/UnmigratableTemporalCallRector.php`, `src/DurableRector/README.md`, `documentation/user/cancellation/`, `documentation/user/comparison/`, `UPGRADE.md`.
- **Overlap**: none known.
- **State**: in progress.
