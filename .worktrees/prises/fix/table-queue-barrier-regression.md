# fix/table-queue-barrier-regression

- **Scope**: refs #1065: `twoWorkersNeverTakeTheSameMessage` hangs on its second barrier when one worker's first take answers empty.
- **Entries**: tests/integration/DurableModule/TableQueue/TableQueueTest.php, Harness.php, Fixture/worker.php.
- **Overlap**: none.
- **State**: in progress.
