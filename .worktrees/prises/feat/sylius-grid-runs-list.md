# feat/sylius-grid-runs-list

- **Scope**: #383 slice B. A Sylius grid data provider over `WorkflowRunCatalogInterface` for the
  runs list, paginated with the catalogue's own cursor (no Pagerfanta). No run-id filter (#557) and
  no workflow-name filter (#558), but a hook left for them.
- **Entries**: `src/DurablePlugin/`, its tests, `src/DurablePlugin/composer.json`
  (`sylius/grid-bundle` only), `sylius/composer.lock` if the plugin's new requirement moves it.
- **State**: taken by bob, reviewer jack. Waiting for #555 to reach main, and for the user to
  confirm how the root static analysis sees the grid classes.
