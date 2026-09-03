# fix/effet-de-bord-presence-du-slot

- **Chantier** : B1 de l'audit. `findSideEffectForSlot()` rend `mixed` et signale « pas enregistré »
  par `null` : un effet de bord dont la closure rend `null` est rejoué à chaque passe et le journal
  grossit d'un `SideEffectRecorded` par replay. Ajout de `hasSideEffectForSlot(): bool` au port —
  la présence d'un slot est un état, pas une valeur.
- **Entrées** : `Port/WorkflowHistorySourceInterface`, ses deux implémentations,
  `ExecutionContext::sideEffect()`, un test, `UPGRADE.md`. Rien d'autre.
- **État** : GREEN, en revue — PR #273.
