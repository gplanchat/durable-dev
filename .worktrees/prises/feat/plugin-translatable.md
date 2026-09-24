# feat/plugin-translatable

- **Scope**: #382. Every literal the plugin prints goes through `|trans` in the `durable` domain, with `translations/durable.en.yaml` and `durable.fr.yaml`; a test renders the page in `fr` and finds no English literal. Stacked on `refactor/plugin-sylius-shaped` (#496).
- **Entries**: `src/DurablePlugin/templates/`, `src/DurablePlugin/translations/`, `src/DurablePlugin/EventListener/AdminMenuListener.php`, `src/DurablePlugin/tests/`.
- **State**: in progress (durable-48, lane D).
