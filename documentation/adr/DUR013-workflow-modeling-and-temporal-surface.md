# DUR013 — Modélisation des workflows et surface Query / Signal / Update

## Statut

Accepté

## Contexte

Temporal expose plusieurs **surfaces** pour interagir avec un workflow en cours : **Query** (lecture d’état cohérente avec le rejeu), **Signal** (apport d’information externe), **Update** (mise à jour avec sémantique de validation/réponse selon le serveur). Le composant Durable doit **modéliser** ces capacités sans s’appuyer sur le SDK officiel interdit (DUR006), tout en restant aligné sur les **concepts** de l’orchestrateur.

Par ailleurs, une **interface** de workflow claire améliore la lisibilité, les tests et l’enregistrement des types auprès du runtime.

## Décision

### Interfaces et nommage

- Chaque **workflow** pertinent est décrit par une **interface** (ou contrat équivalent) dont le nom reflète **l’intention** (verbe + nom de cas d’usage, par ex. `ProvisionTenant`, `SyncCatalog`).
- Les **paramètres** du point d’entrée principal sont des **types du domaine** ou du composant, **sérialisables** (DUR007) ; éviter les DTO fourre-tout opaques lorsque des value objects explicites améliorent la clarté et la stabilité du schéma.

### Point d’entrée principal

- Une méthode **principale** représente le démarrage / la coroutine durable du scénario (équivalent **WorkflowMethod** dans le vocabulaire Temporal).
- Le **constructeur** de l’implémentation reçoit le **contexte runtime** (awaitables, stubs d’activité — DUR003, DUR004) ; cette règle **prime** sur les anciens modèles où un SDK imposait un constructeur sans paramètres : ici le **runtime Durable** injecte le contexte.

### Query, Signal, Update

- **Query** : méthodes dédiées exposant une **vue** de l’état workflow, **synchrone** du point de vue du modèle d’exécution Temporal, **sans effet de bord** ; types de retour sérialisables et stables pour les observateurs.
- **Signal** : méthodes recevant des **messages** externes ; elles **mutent** l’état prévu par le workflow de façon **déterministe** et **rejouable**.
- **Update** : lorsque le serveur et le composant les supportent, méthodes pour **proposer** une modification avec validation ; sémantique (idempotence, réponse) documentée par le composant.

Ces surfaces sont **optionnelles** par workflow ; leur **mapping** vers les RPC Temporal est entièrement du ressort des **adaptateurs** (DUR012), pas du code métier du workflow.

### Déterminisme

- Toute logique dans les handlers Query/Signal/Update respecte les mêmes règles de **déterminisme** que le corps principal (DUR003) : pas d’I/O direct, pas de sources non reproductibles.

## Conséquences

- La documentation utilisateur du composant doit lister, pour chaque workflow, les **signatures** exposées (principal + Query/Signal/Update éventuels).
- Les tests peuvent valider le comportement via le backend **In-Memory** (DUR005) en simulant signaux et requêtes.
