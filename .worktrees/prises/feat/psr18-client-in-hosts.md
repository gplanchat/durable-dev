# feat/psr18-client-in-hosts

- **Chantier** : #448 (part of #425) — the application hands its PSR-18 client to `transport=http`: Symfony, Laravel, Magento.
- **Entrées** : `src/DurableBundle/DependencyInjection/` (Temporal client definition), `src/DurableLaravel/DurableServiceProvider.php` + `config/durable.php`, `src/DurableModule/Runtime/RuntimeFactory.php`, their tests, host docs.
- **État** : en relecture — PR #449
