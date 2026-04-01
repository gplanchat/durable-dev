# Cartographie d’extraction vers `durable-bridge-dbal`

WA003-dbal-bridge-extraction-map  
===

Introduction
---

Ce document **cartographie** les classes et fichiers à regrouper dans un futur package **`gplanchat/durable-bridge-dbal`**, miroir conceptuel de `src/Bridge/Temporal`, comme prévu dans le plan d’audit Temporal vs DBAL. Aucun package Composer n’est créé dans cette étape : il s’agit d’une **référence** pour la refactorisation.

Périmètre cible du package
---

| Élément actuel (monorepo) | Rôle |
|---------------------------|------|
| [`src/Durable/Store/DbalEventStore.php`](../../src/Durable/Store/DbalEventStore.php) | Implémentation `EventStoreInterface` (tables SQL) |
| [`src/Durable/Store/DbalWorkflowMetadataStore.php`](../../src/Durable/Store/DbalWorkflowMetadataStore.php) | `WorkflowMetadataStore` |
| [`src/Durable/Store/DbalChildWorkflowParentLinkStore.php`](../../src/Durable/Store/DbalChildWorkflowParentLinkStore.php) | `ChildWorkflowParentLinkStoreInterface` |
| [`src/Durable/Transport/DbalActivityTransport.php`](../../src/Durable/Transport/DbalActivityTransport.php) | Transport activités (outbox DBAL) |
| Dépendances | `doctrine/dbal`, types du cœur `gplanchat/durable` (`EventStoreInterface`, événements, etc.) |

**Reste dans le cœur** (`src/Durable`) : ports, modèles d’événements, `EventStoreInterface`, `WorkflowMetadataStore` (interface), pas les implémentations DBAL.

**Bundle** (`DurableBundle`) : `DurableExtension` / `Configuration` continue de **référencer** les implémentations par clé de configuration ; le package bridge expose les classes concrètes.

Prochaines étapes (hors périmètre immédiat)
---

- Définir `composer.json` du satellite (autoload PSR-4, contraintes `doctrine/dbal`).
- Brancher **splitsh** / publication ([ADR017](../adr/ADR017-splitsh-ci-and-satellite-pushes.md)) si dépôt distant dédié.
- Tests : déplacer ou dupliquer les tests DBAL dans le package ou garder les tests d’intégration dans le monorepo.

Références
---

- Plan d’audit Temporal vs DBAL (extraction §4).
- [ADR004](../adr/ADR004-ports-and-adapters.md) — ports et adaptateurs.
