# fix/conteneur-hygiene

- **Chantier** : M16 et M12 de l'audit. M16 : `ActivityHandlerPass` pose des callables
  `[new Reference($invoker), '__invoke']` sur l'exécuteur, donc le conteneur construit **tous** les
  invokers — et tous les handlers — pour en exécuter un. M12 : 48 services publics sur 60, y compris
  des décorateurs internes que rien n'a de raison de tirer du conteneur.
- **Entrées** : `ActivityHandlerPass`, `RegistryActivityExecutor`, `DurableExtension`, les tests,
  `UPGRADE.md`.
- **État** : rédaction.
