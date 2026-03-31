# ADR019 — Pagination curseur pour l’EventStore (DBAL et Temporal)

ADR019-event-store-cursor-pagination  
===

Status: **Accepted**

## Context

Durable expose deux implémentations de `EventStoreInterface` :

- **`DbalEventStore`** : lignes en base, ordre stable par clé surrogate **`id`** (monotone par ligne insérée).
- **`TemporalJournalEventStore`** : historique serveur via **`GetWorkflowExecutionHistory`**, paginé par **`next_page_token`**.

Sans discipline commune, deux problèmes opérationnels apparaissent :

1. **Mémoire côté client** — Sur MySQL avec requêtes bufferisées, un `SELECT` qui ramène tout le flux d’une exécution peut charger l’intégralité du jeu de lignes dans le client PHP même si le code itère ensuite. La mitigation « connexion dédiée + lecture unbuffered » ([ADR016](ADR016-dedicated-dbal-connection-and-unbuffered-reads.md)) ne doit pas être le **seul** levier.
2. **Temporal** — L’historique est **intrinsèquement** paginé ; ignorer `next_page_token` ou reconstruire des pages par **offset** sur une liste en mémoire est incorrect.

## Decision

### 1. DBAL — pagination **keyset** (curseur)

Pour `readStream` / `readStreamWithRecordedAt` :

- Parcourir par **pages** avec une condition du type  
  `WHERE execution_id = ? AND id > ? ORDER BY id ASC LIMIT ?`
- Faire avancer le curseur avec le dernier **`id`** lu ; **ne pas** utiliser `OFFSET` pour parcourir le flux (coût et sémantique instable sous charge).
- Borner **`LIMIT`** par une constante interne (ordre de grandeur centaines / milliers), documentée ici comme invariant de taille de page.
- Continuer à **yield** événement par événement pour préserver le contrat `iterable` du port.

Cette stratégie réduit la taille maximale d’un résultat SQL **par requête** et aligne le moteur DBAL sur une lecture « par curseur » sans exiger une connexion unbuffered pour les déploiements standards.

### 2. Temporal — jeton serveur

Pour la lecture d’historique utilisée par le bridge journal :

- Enchaîner les appels **`GetWorkflowExecutionHistory`** avec **`next_page_token`** jusqu’à épuisement.
- Ne pas simuler de pagination par **offset** sur des événements déjà chargés en PHP.
- Respecter les limites documentées côté API (`maximum_page_size` / équivalent) lorsque le client les expose.

La fusion des pages en une structure `History` complète pour le rejeu peut **accumuler** l’historique en mémoire pour une exécution donnée ; le risque de très longs journaux reste couvert par **continue-as-new** et par la surveillance de taille d’historique ([ADR014](ADR014-temporal-journal-eventstore-bridge.md), [OST001](../ost/OST001-future-opportunities.md)).

### 3. Rapport avec ADR016

- La **connexion DBAL dédiée** reste valable pour l’**isolation** des transactions et des curseurs.
- Le mode **unbuffered MySQL** reste une **option** pour des cas extrêmes ou des contraintes driver, **après** avoir appliqué la pagination keyset pour les lectures du journal.

## Consequences

- Le code de `DbalEventStore` implémente la boucle keyset avec une taille de page par défaut (500) ; un **troisième argument constructeur** permet de baisser la taille en **tests d’intégration** pour forcer plusieurs pages SQL sans insérer des centaines d’événements. Les tests couvrent l’ordre, le comptage et l’absence de mélange entre `execution_id`.
- Le code Temporal (`HistoryPageMerger` ou équivalent) suit `next_page_token` pour l’historique complet nécessaire au rejeu.
- Les opérateurs peuvent **réduire** la dépendance aux réglages PDO unbuffered pour la seule lecture du journal.

## References

- [ADR004 — Ports et adapters](ADR004-ports-and-adapters.md)
- [ADR014 — Temporal journal EventStore bridge](ADR014-temporal-journal-eventstore-bridge.md)
- [ADR016 — Connexion DBAL dédiée et lectures unbuffered](ADR016-dedicated-dbal-connection-and-unbuffered-reads.md)
- `Gplanchat\Durable\Store\DbalEventStore`
- `Gplanchat\Bridge\Temporal\Journal\HistoryPageMerger`
