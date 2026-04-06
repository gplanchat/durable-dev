# DUR002 — Repositories CQRS vers l’API Temporal

## Statut

Accepté

## Contexte

Au sens **CQRS**, les opérations sur un workflow durable se séparent naturellement en :

- **écritures** : démarrer un workflow, signaler, annuler, terminer, ou toute autre mutation exposée par l’orchestrateur ;
- **lectures** : interroger l’état, l’historique métier visible, les métadonnées nécessaires à l’UI ou aux intégrations.

Le composant doit offrir des points d’entrée stables côté application, indépendants des détails du client HTTP/gRPC utilisé pour parler à Temporal.

## Décision

Le composant Durable définit deux familles de **repositories** (interfaces ou abstractions équivalentes) :

### Repositories « Command »

- Envoi de **messages d’écriture** vers l’**API Temporal** (démarrage, signaux, requêtes de type commande côté orchestrateur, annulation, etc.).
- Contrat orienté **intention** (commandes du domaine d’orchestration), pas exposition brute d’un client SDK officiel (voir DUR006).

### Repositories « Query »

- Envoi de **messages de lecture** vers l’**API Temporal** (état, résultats, descripteurs nécessaires aux vues).
- Même séparation : le repository encapsule le transport et le mapping vers les modèles du composant.

### Principes

- **Ports** : les repositories sont des ports ; les adaptateurs **Temporal** et **In-Memory** (DUR005) implémentent ces ports différemment.
- **Séparation client / adaptateur** : le transport vers le serveur Temporal et le mapping vers les types du composant suivent **DUR012** ; la classification des erreurs et les retries suivent **DUR011**.
- **Pas de fuite de détails** : les types retournés vers le code applicatif restent ceux du composant Durable, pas des types propriétaires d’un SDK tiers interdit.
- **Testabilité** : le backend In-Memory permet de valider la logique sans cluster Temporal ; les tests d’adaptateurs sont cadrés par **DUR015**.

## Conséquences

- La surface d’API des repositories doit être versionnée ou stabilisée avec prudence : elle fixe le contrat pour les applications hôtes.
- Toute nouvelle capacité Temporal requise par le produit transite par l’extension de ces ports plutôt que par l’usage direct d’un client non conforme à DUR006.
