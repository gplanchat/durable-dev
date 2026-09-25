# feat/show-the-recorded-wait

- **Scope**: #324, reopened. The wait the catalogues record (`WorkflowRunDescription::$waitingOn`,
  worded by `RunDashboard`) is shown nowhere. Render it on the Sylius plugin run list, the Magento
  grid and detail page, and the Symfony bench dashboard; the profiler panel is pending alice's call
  (it has no catalogue to read from). Reuse the existing field, no new port method. Temporal not
  filling it is out of scope (#514).
- **Entries**: `src/DurablePlugin/templates/admin/dashboard/_dashboard.html.twig` and its render
  test; `src/DurableModule/Ui/DataProvider/ProcessListing.php`,
  `src/DurableModule/view/adminhtml/ui_component/durable_process_listing.xml`,
  `src/DurableModule/view/adminhtml/templates/process/detail.phtml` and their tests;
  `symfony/templates/dashboard/` and its test.
- **State**: in progress — sabrina, reviewer vera.
