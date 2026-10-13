# fix/table-queue-remaining-clocks

- **Scope**: refs #1065: the delayed-message test of the table queue compared a database delay with a PHP `hrtime` delta; audit of the other timing assertions of tests/integration/DurableModule.
- **Entries**: tests/integration/DurableModule/TableQueue/TableQueueTest.php, Fixture/worker.php.
- **Overlap**: none.
- **State**: in progress.
