# DUR011 — Erreurs, classification et politiques de retry

## Statut

Accepté

## Contexte

Les **activités** (DUR004) interagissent avec l’extérieur ; les **adaptateurs** vers Temporal (DUR002) subissent réseau, timeouts et erreurs métier. L’**orchestrateur** applique des **retries** sur certaines défaillances seulement. Le **workflow** (DUR003) doit rester déterministe : il ne gère pas l’I/O brut, mais il doit pouvoir **réagir** aux échecs modélisés dans l’historique (activités en erreur, compensations).

## Décision

### Classification des erreurs

1. **Erreurs métier / non retentables** (ex. validation impossible, ressource définitivement absente, règle métier violée) : en principe **pas** de retry automatique côté Temporal pour l’activité concernée ; le workflow peut enchaîner une **compensation** ou terminer en échec contrôlé.
2. **Erreurs système / transitoires** (timeouts réseau, indisponibilité temporaire, surcharge) : **candidats** aux retries avec backoff, selon la politique configurée sur l’activité ou le client.

Les **exceptions** levées dans les activités ou les adaptateurs **ne doivent pas** traverser les couches sans **traduction** : les ports exposés au domaine applicatif utilisent des **types d’erreur** du composant ou du domaine hôte, pas des erreurs brutes du client HTTP/gRPC.

### Chaînage et contexte

- Conserver la **cause** (`previous`) lorsque pertinent pour le diagnostic.
- Enrichir avec un **contexte structuré** (identifiants de workflow, d’activité, opération) côté **activités** et **workers** — jamais de secrets en clair dans les logs (voir besoins de confidentialité globaux du projet).

### Retries

- **Paramètres** (nombre max, intervalle, exceptions non retentables) sont portés par la **configuration** des activités dans le composant (stubs, options d’exécution), alignée sur les capacités Temporal.
- Les **workflows** ne « retentent » pas eux-mêmes par effets de bord : ils **rejouent** l’historique ; les retries sont une propriété des **tâches d’activité** et du **moteur**.

### Résilience hors orchestrateur

Pour appels HTTP ou services externes **dans** une activité, des patterns de **résilience** (timeouts, limitation de débit, disjoncteur) restent **côté activité** ou **côté client dédié**, pas dans le corps du workflow.

## Conséquences

- Les tests (DUR009, DUR015) doivent couvrir les chemins « métier vs transitoire » de façon **déterministe** (doubles ou backends contrôlés).
- La documentation d’exploitation (DUR017) s’appuie sur cette classification pour l’alerting et le diagnostic.
