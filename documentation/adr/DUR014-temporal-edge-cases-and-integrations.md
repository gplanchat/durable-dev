# DUR014 — Cas limites temporels et intégrations externes

## Statut

Accepté

## Contexte

Les systèmes distribués combinent **événements asynchrones**, **APIs tierces** et **état partiel** : des **courses** (un traitement lit une donnée avant qu’une autre étape ne l’ait produite) sont fréquentes hors orchestration centralisée. **Temporal** impose un **ordre d’historique** et un **état de workflow** rejouable, ce qui atténue une classe de problèmes, mais les **intégrations** (identité, files, webhooks) restent sources de retards et d’incohérences transitoires.

## Décision

### Ordre et historique

- Le **workflow** est la source de vérité pour **l’enchaînement** des étapes visibles dans l’historique : les décisions sont **sérialisées** par le modèle d’exécution, pas par des bus d’événements concurrents non ordonnés côté application.

### Attendre une condition métier

Lorsqu’une **donnée externe** n’est pas encore disponible (ex. métadonnée créée par un autre système), deux familles de stratégies **dans les activités** sont possibles :

1. **Polling contrôlé** : une activité (ou une séquence) **relit** l’état externe jusqu’à un critère ou un timeout, avec backoff ; le workflow **réitère** des attentes via l’historique (timers / nouvelles tentatives selon les options).
2. **Signal** (DUR013) : le système externe (ou un adaptateur) **notifie** le workflow lorsque la condition est remplie ; évite le bruit de polling si le canal existe.

Le choix est **cas par cas** : latence, disponibilité du canal de signal, coût des appels, idempotence des lectures.

### Idempotence et effets de bord

- Les **activités** appelées plusieurs fois (retries) doivent être **idempotentes** ou **protégées** (clés d’idempotence côté service externe) lorsque l’effet n’est pas naturellement répétable (DUR004, DUR011).

### Intégrations fragiles

- Timeouts explicites, limites de débit et **résilience** (DUR011) côté **activité** ou client dédié.
- Ne pas encoder de **délais arbitraires** non enregistrés dans le workflow pour « attendre » : préférer **timers** / **activités** modélisées dans l’historique.

### Cohérence avec l’EventStore

- La **lecture** de l’historique via l’EventStore (DUR001) pour inspection ou audit ne remplace pas les **Query** sur le workflow pour l’état **métier** courant : les rôles sont distincts (journal brut vs interface de requête).

## Conséquences

- Les scénarios d’intégration **complexes** doivent être couverts par des **tests** (DUR010, DUR015) incluant rejeu et retries simulés.
- L’**observabilité** (DUR017) doit corréler identifiants de workflow et appels externes pour le diagnostic des courses résiduelles.
