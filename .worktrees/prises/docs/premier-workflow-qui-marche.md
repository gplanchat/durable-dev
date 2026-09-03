# docs/premier-workflow-qui-marche

- **Chantier** : B5 et B6 de l'audit. B5 : le guide pose `#[AsActivity]` sur l'implémentation, alors
  que cet attribut est lu **sur le contrat** comme préfixe de nommage optionnel — et il y remplace
  `#[AsActivityHandler]`, le seul que le bundle autoconfigure. Le premier workflow du guide ne
  s'enregistre donc pas. B6 : le parcours s'arrête sur un `dispatchNewWorkflowRun()` sans jamais
  dire qu'un consommateur doit tourner, ni que les transports `in-memory://` qu'il prescrit ne
  survivent pas au processus.
- **Entrées** : `documentation/user/getting-started/`, `activities/`, `packages/` — les deux langues.
  Aucun code.
- **État** : en revue — PR #276.
