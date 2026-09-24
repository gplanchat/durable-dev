# chore/dead-code-bundle-plugin

- **Scope**: #373 slice 1 — the plugin's tests kept out of its dist archive (`.gitattributes`),
  the unused `$tags` in `WorkflowPass`, one shared body for `assertWorkflowFailed()`. 1a/1b are
  #337's; 5a, 5c, 6 and 8 wait on #476, #485, #515 and #519.
- **Entries**: `src/DurablePlugin/.gitattributes`,
  `src/DurableBundle/DependencyInjection/Compiler/WorkflowPass.php`,
  `src/Durable/Testing/DurableTestCase.php`, `src/DurableBundle/Testing/DurableBundleTestTrait.php`,
  `src/Durable/Testing/JournalAssertions.php` (new), its test.
- **State**: branch pushed (54337df8), PR held: epic #310 queue full (#493, #517); main to merge in after #521 — dave.
