# feat/backend-data-parity

- **Chantier** : change OpenSpec `backend-data-parity` — uniformiser ce que les quatre backends
  (mémoire, DBAL, Illuminate, Temporal) exposent derrière les mêmes ports. Point de départ :
  l'identité d'une exécution, qui vit dans `runId` sur trois backends et dans un memo Temporal
  jamais exposé sur le quatrième.
- **Entrées** : `openspec/changes/backend-data-parity/` uniquement. Aucun code de `src/` dans cette
  prise — la tranche d'implémentation viendra après acceptation de la proposition.
- **État** : en revue — PR #269. Proposition seule ; la §0 des tâches (sonder le serveur Temporal)
  conditionne toute tranche d'implémentation.
