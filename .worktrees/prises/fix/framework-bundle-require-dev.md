# fix/framework-bundle-require-dev

- **Chantier** : `symfony/framework-bundle` est en `require` sans être utilisé — `require-dev` + `suggest` pour `durable-bundle` (le trait de test livré appelle `KernelTestCase::getContainer()`), retrait sec pour `durable-plugin` qui ne s'en sert nulle part
- **Entrées** : `src/DurableBundle/composer.json`, `src/DurablePlugin/composer.json`
- **État** : en relecture — PR #242.
