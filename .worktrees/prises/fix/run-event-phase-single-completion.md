# fix/run-event-phase-single-completion

- **Scope**: #332, follow-up slice. #262 and the Magento phase column shipped in PR #501 (merged,
  631c525b). What remains: the profiler panel and the Sylius plugin panel render
  `WorkflowRunEvent::phase`.
- **Entries**: `src/DurableBundle/Profiler/DurableProfilerEventPresentation.php`,
  `src/DurableBundle/Resources/views/Collector/durable.html.twig`, the collector's row builders
  once #488 merges; `src/DurablePlugin/templates/admin/dashboard/_dashboard.html.twig` (event rows) once #519 lands; their tests.
- **State**: waiting — profiler after #488 (antoine's #337 rename stacks on it), plugin after #519. sabrina.
