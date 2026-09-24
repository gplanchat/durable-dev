# feat/plugin-run-event-phase

- **Scope**: #332, last slice. #262 and the Magento phase column shipped in #501, the profiler's in
  #524 (022147bc). What remains: the Sylius plugin's run page renders `WorkflowRunEvent::phase`.
- **Entries**: `src/DurablePlugin/templates/admin/dashboard/_dashboard.html.twig` (event rows),
  `src/DurablePlugin/translations/durable.{en,fr}.xlf` (`phase.*`),
  `src/DurablePlugin/tests/Integration/TheDashboardRendersARunHistoryTest.php`.
- **State**: in review — PR #533 (closes #332), reviewed OK by vera. sabrina.
