# fix/ui-strings-in-english

- **Work item**: WA006 says English everywhere in code, and the shipped user interfaces still speak
  French — the Symfony profiler panel, the Sylius admin dashboard, the Magento adminhtml screens,
  the `durable:diagnose-execution` console output, the bundle configuration descriptions, and the
  assertion messages of the conformance test cases the packages export to their consumers.
- **Entry points**: `src/DurableBundle/Resources/views/Collector/durable.html.twig`,
  `src/DurableBundle/Profiler/DurableProfilerEventPresentation.php`,
  `src/DurableBundle/DataCollector/DurableDataCollector.php`,
  `src/DurableBundle/Command/DiagnoseExecutionCommand.php`,
  `src/DurableBundle/DependencyInjection/Configuration.php`,
  `src/DurablePlugin/Resources/views/admin/dashboard/index.html.twig`,
  `src/DurableModule/view/adminhtml/**`, `src/DurableModule/etc/di.xml`,
  `src/Durable/Testing/*`, `src/DurableBundle/Testing/DurableBundleTestTrait.php`.
  Strings only — comments are a separate slice, and so are the demonstration packages.
- **State**: in progress.
