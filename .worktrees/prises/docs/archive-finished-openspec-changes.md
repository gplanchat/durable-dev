# docs/archive-finished-openspec-changes

- **Scope**: #377 — translate then archive `dashboard-presentation`, `demo-nexus-magento`,
  `nexus-garde-des-noms` (deltas applied to `openspec/specs/`, WA006 rule: English before archive);
  fix `#[Workflow]` in `laravel-host`/`magento-host` specs and the value-object names in
  `nexus-operations`. `demo-nexus-laravel` and `backend-data-parity` stay put.
- **Entries**: `openspec/changes/{dashboard-presentation,demo-nexus-magento,nexus-garde-des-noms}/`,
  `openspec/changes/archive/`, `openspec/specs/`.
- **Done when**: `openspec list` shows only `backend-data-parity` and `demo-nexus-laravel`;
  `openspec validate --specs --strict` passes; `grep -rn '#\[Workflow\]' openspec/specs` is empty.
- **State**: in review — PR #500.
