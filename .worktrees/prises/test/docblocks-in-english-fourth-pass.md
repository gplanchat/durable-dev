# test/docblocks-in-english-fourth-pass

- **Scope**: #400, first PR (slices 1+2): the French prose (docblocks, comments, assert messages)
  and data-provider labels left in the test suite, and the French test-data strings of the same
  files. Deliberate non-ASCII inputs stay. The second PR (the remaining test-data strings and the
  guard over `tests/`) waits for #476.
- **Entries**: `tests/unit/Durable/Replay/{ActivityPayloadDivergence,ChildWorkflowSlotDivergence}Test.php`,
  `tests/unit/Bridge/Temporal/Worker/{PayloadForSlot,NexusHistoryReading,NexusTypedFailure}Test.php`,
  `tests/unit/DurablePhpstan/StubMethodsExtensionTest.php`,
  `tests/unit/Durable/Nexus/{NexusEndpoint,NexusOperationHeaders,NexusServiceAndOperationName}Test.php`,
  `tests/unit/Durable/{SearchAttributes,TaskQueue}Test.php`,
  `tests/integration/Temporal/{NexusEndpointNameRules,NexusServiceAndOperationNameRules}Test.php`.
- **Done when**: reading each file finds no French outside deliberate non-ASCII inputs; the suite
  and PHPStan/Psalm/cs are green.
- **State**: in progress — bob, reviewer sabrina.
