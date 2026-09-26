# fix/plugin-routes-without-sylius

- **Scope**: #520. `symfony/yaml` in `src/DurablePlugin/composer.json` `require` (the user's decision
  on #520); a plugin prefix of its own when `sylius_admin.path_name` is not defined; a kernel test
  without Sylius that reaches #519's 404 on the dashboard route.
- **Entries**: `src/DurablePlugin/composer.json` (that one line), `src/DurablePlugin/config/`,
  `src/DurablePlugin/DependencyInjection/`, their tests.
- **Not in scope**: `sylius/grid-bundle` (bob, #383 slice B); routes stay YAML.
- **State**: taken, starts once #555 is on main — emma. Reviewer: jack.
