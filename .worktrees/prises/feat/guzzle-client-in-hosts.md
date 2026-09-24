# feat/guzzle-client-in-hosts

- **Chantier** : #437 (part of #425) — the application hands its Guzzle client to `transport=guzzle`: Symfony, Laravel, Magento.
- **Entrées** : `src/DurableBundle/DependencyInjection/` (Temporal client definition), `src/DurableLaravel/DurableServiceProvider.php` + `config/durable.php`, `src/DurableModule/Runtime/RuntimeFactory.php`, their tests, host docs.
- **État** : en relecture — PR #438
