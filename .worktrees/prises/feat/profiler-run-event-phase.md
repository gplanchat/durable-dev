# feat/profiler-run-event-phase

- **Scope**: #332, follow-up slice. #262 and the Magento phase column shipped in PR #501 (merged,
  631c525b). What remains: the profiler panel and the Sylius plugin panel render
  `WorkflowRunEvent::phase`.
- **Entries**: `src/Durable/Observation/JournalRunHistoryReader.php` (`phaseOf()` made public),
  `src/DurableBundle/DataCollector/DurableDataCollector.php` (store event rows),
  `src/DurableBundle/Resources/views/Collector/durable.html.twig` (journal table); then
  `src/DurablePlugin/templates/admin/dashboard/_dashboard.html.twig` (event rows) once #519 lands;
  their tests.
- **State**: in review — profiler part is PR #524 on `feat/profiler-run-event-phase` (antoine's #337 rename
  stacks on it), plugin part after #519. sabrina.
