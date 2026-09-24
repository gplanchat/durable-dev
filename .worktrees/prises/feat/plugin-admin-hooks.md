# feat/plugin-admin-hooks

- **Scope**: #383, slice A. The Sylius plugin's dashboard is composed through the Sylius 2 admin
  hooks (sidebar, navbar, header, flashes, footer come from `sylius_admin.common.index`), with a
  previous-page link from a stack of cursors in the URL (no change to the catalog port), and the
  expanded payloads no longer render white on white (#256). The hooks config is prepended only
  when `sylius_twig_hooks` is registered; without the Sylius admin, the route answers 404, never a
  Twig error. Slice B (a grid data provider) waits on the cursor-vs-Pagerfanta decision.
- **Entries**: `src/DurablePlugin/templates/admin/dashboard/`, `src/DurablePlugin/Controller/`,
  `src/DurablePlugin/DependencyInjection/DurablePluginExtension.php`, `src/DurablePlugin/translations/`,
  `src/DurablePlugin/tests/`, `sylius/tests/Functional/DurableDashboardTest.php`.
- **Order**: plugin template: vera first (#383 A), then sabrina's #332 plugin column. Stacked on
  #497 until it lands.
- **State**: in progress — vera; reviewer bob.
