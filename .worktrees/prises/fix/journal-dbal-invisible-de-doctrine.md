# fix/journal-dbal-invisible-de-doctrine

- **Chantier** : B4 de l'audit. Les tables du pont DBAL ne sont déclarées à Doctrine par aucun
  `configureSchema` ni écouteur `postGenerateSchema` — un docbloc de `DurableSchema` prétend
  pourtant l'inverse. `doctrine:migrations:diff` les voit donc comme orphelines et génère des
  `DROP TABLE`. Ajout de `configureSchema()` au pont, d'un écouteur côté bundle, et d'un
  interrupteur `auto_setup` pour que le DDL paresseux cesse quand les migrations prennent la main.
- **Entrées** : `src/Bridge/Dbal/Schema/DurableSchema.php`, un écouteur dans `src/DurableBundle/`,
  sa configuration et son enregistrement, un test, `UPGRADE.md` si rupture. Rien d'autre.
- **État** : en revue — PR #274.
