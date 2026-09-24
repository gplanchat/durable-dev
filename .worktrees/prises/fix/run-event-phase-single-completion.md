# fix/run-event-phase-single-completion

- **Scope**: #332. #260 and #261 are closed on main; what remains is #262 (a successful activity
  writes `ActivityCompleted` only, no duplicate `ActivityTaskCompleted`) and the panels rendering
  `WorkflowRunEvent::phase`. The Sylius plugin panel waits for #497 (template moved by #496/#497).
- **Entries**: `src/Durable/Worker/ActivityMessageProcessor.php`, `UPGRADE.md`,
  `src/DurableModule/view/adminhtml/templates/process/detail.phtml`,
  `src/DurableBundle/Profiler/DurableProfilerEventPresentation.php`,
  `src/DurableBundle/Resources/views/Collector/durable.html.twig`, their tests.
- **State**: in review — PR #501 (#262 + Magento), sabrina. Profiler and plugin panels: follow-up stacked on #488 and #497.
