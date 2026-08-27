# fix/ci-temporal-gate-aveugle

- **Chantier** : le job CI « Tests d'intégration Temporal » est vert en ne testant
  rien — 13 tests, 3 assertions, 10 sautées, en 45 ms. Constat 7.3 du change
  `query-plumbing-leaves-the-environment`.
- **Entrées** : trouver pourquoi les tests sautent en CI, puis rendre le job
  capable d'échouer. Succès attendu : le job devient **rouge** sur l'arbre actuel.
- **État** : en cours
