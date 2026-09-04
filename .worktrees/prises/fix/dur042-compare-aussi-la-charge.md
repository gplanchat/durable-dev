# fix/dur042-compare-aussi-la-charge

- **Chantier** : la garde de divergence (DUR042) ne compare que le **nom** de l'activité au slot
  (`activityNameForSlot()`). La **charge** ne traverse jamais la comparaison : un rejeu qui
  recalcule un payload différent voit le journal servir l'ancien résultat, la nouvelle charge
  partir à la poubelle, et l'exécution se terminer en succès. Mesuré par mutation sur la maquette
  d'agent — 9 charges calculées, 3 journalisées, 6 divergences avalées, suite verte.
  Ajout de `activityPayloadForSlot(): ?array` au port et d'une empreinte canonique comparée dans
  `ExecutionContext::activity()`.
- **Entrées** : `Port/WorkflowHistorySourceInterface`, ses deux implémentations,
  `ExecutionContext::activity()`, des tests, `UPGRADE.md`. Rien d'autre.
- **Hors périmètre** : les slots Nexus et workflow enfant ont le même trou. Pas touchés ici.
- **État** : GREEN, en revue — PR #282.
