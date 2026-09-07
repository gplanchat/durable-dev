# fix/profileur-en-production

- **Chantier** : B2 et B3 de l'audit, même surface. B2 : `registerProfiler()` est appelé sans
  condition, l'observateur passe donc sur le chemin chaud de production, et `durable.execution_trace`
  n'a pas de tag `kernel.reset` — dans `messenger:consume`, où il n'y a pas de requête, sa timeline
  grossit sans borne. B3 : `DurableDataCollector` range des charges utiles brutes dans `$this->data`
  et `__serialize()` les rend telles quelles ; un objet non sérialisable casse le profil entier.
- **Entrées** : `DurableExtension::registerProfiler()`, `DurableDataCollector`, un observateur nul
  dans le cœur, les tests. Rien d'autre.
- **État** : en revue — PR #275.
