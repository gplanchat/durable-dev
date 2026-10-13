# refactor/remove-cancellation-type

- **Work item**: #1052, remove the `cancellationType` activity option (decision 2 of #982), with a
  Rector rule, the options page and an UPGRADE.md section.
- **Entry points**: `src/Durable/Activity/`, `src/Durable/Attribute/Activities.php`,
  `src/DurablePhpstan/Rules/ActivityStubCouldBeParameterRule.php`, `src/DurableRector/`,
  `documentation/user/workflows/_index*.md`, `UPGRADE.md`.
- **State**: in progress.
