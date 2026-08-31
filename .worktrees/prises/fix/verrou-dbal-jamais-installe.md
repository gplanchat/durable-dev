# fix/verrou-dbal-jamais-installe

- **Chantier** : `SingleResumeLockMiddleware` est enregistré avec `->addTag('messenger.middleware')`, balise que Symfony ne consomme pas — le verrou n'est installé dans aucun bus, alors que la doc promet le contraire et que c'est la seule garde du backend DBAL contre deux reprises concurrentes
- **Entrées** : `src/DurableBundle/DependencyInjection/{DurableExtension.php,Compiler/}`, `src/DurableBundle/DurableBundle.php`, `tests/unit/DurableBundle/`
- **État** : en relecture — PR #245.
