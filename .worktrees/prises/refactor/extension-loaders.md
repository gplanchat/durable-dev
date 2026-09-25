# refactor/extension-loaders

- **Scope**: #342, C0–C13 of the plan on the issue. `DurableContainerSurfaceTest` pins the
  container the extension builds (three backends, profiler on and off); `DurableExtension`'s
  `register*` methods move into PHP config files under `src/DurableBundle/Resources/config/services/`
  with the snapshot byte-identical; the three inconsistencies are fixed in their own commits, the
  snapshot diff showing each; definitions move to `durable.*` ids with class aliases kept. C14 (the
  public ids) waits for the user's decision.
- **Entries**: `src/DurableBundle/DependencyInjection/DurableExtension.php`,
  `src/DurableBundle/Resources/config/services/`, `tests/unit/DurableBundle/DependencyInjection/`,
  `UPGRADE.md`.
- **State**: in progress — vera; reviewer antoine. PR opened after C0–C4, then pushed as it goes.
