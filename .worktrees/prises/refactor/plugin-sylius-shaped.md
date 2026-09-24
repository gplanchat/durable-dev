# refactor/plugin-sylius-shaped

- **Scope**: #381. The plugin takes the shape of a Sylius 2 plugin without a hard Sylius dependency (owner decision, 2026-09-24): `type: sylius-plugin`, the Sylius 2 tree (`config/`, `templates/` at the package root), the admin route under `%sylius_admin.path_name%`, the menu entry by route. `UPGRADE.md` for the moved route import.
- **Entries**: `src/DurablePlugin/` (composer.json, tree, extension, listener, tests, README), `sylius/config/routes/`, `UPGRADE.md`.
- **State**: in progress (durable-48, lane D).
