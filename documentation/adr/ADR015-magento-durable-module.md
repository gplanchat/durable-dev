# ADR015 — Module Magento `Gplanchat_DurableModule` (DBAL / Temporal)

## Statut

Accepté (implémentation initiale dans le monorepo).

## Contexte

Magento 2.4 doit pouvoir intégrer **gplanchat/durable** sans Symfony Messenger dans le module, sans RoadRunner, avec le code du module versionné sous **`src/DurableModule/`** (package Composer `gplanchat/durable-magento`).

## Décision

- **Emplacement** : module Magento = répertoire **`./src/DurableModule/`**, consommé par une installation Magento dans **`./magento/`** via dépôts Composer `path` (voir `magento/README.md`).
- **Deux backends configurables** (`durable/execution/backend` dans `config.xml` / scope store) :
  - **`dbal`** : moteur Durable + tables MySQL (`db_schema.xml`) + `Doctrine\DBAL\Connection` construite depuis le PDO Magento (`SharedDoctrineConnection`).
  - **`temporal`** : placeholder (`TemporalExecutionBackend::isOperational()` = `false`) jusqu’à ADR / implémentation worker+client **sans RoadRunner**.
- **CLI activités (DBAL)** : `bin/magento gplanchat:durable:activities:consume` utilise `ActivityMessageProcessor` (lib) + `DbalActivityTransport` / `DbalEventStore`. Tant qu’aucun `WorkflowResumeDispatcher` Magento n’est fourni, la commande injecte `NullWorkflowResumeDispatcher` (pas de reprise workflow distribuée automatique après activité).
- **Messenger** : réservé au bundle Symfony ([ADR005](ADR005-messenger-integration.md)) ; absent du module Magento.

## Conséquences

- Le schéma déclaratif Magento doit rester aligné avec les tables attendues par `DbalEventStore`, `DbalWorkflowMetadataStore`, `DbalChildWorkflowParentLinkStore`, `DbalActivityTransport`.
- PHPStan du monorepo n’analyse pas `src/DurableModule` par défaut (pas de dépendance `magento/framework` dans le vendor racine).
- Temporal : documenter séparément le choix d’implémentation (SDK ciblé, gRPC, worker PHP) dès qu’on active le backend `temporal`.

## Références

- Plan Cursor « Durable × Magento 2.4 »
- [PLAN001](../plans/PLAN001-lib-decouple-messenger.md)
- [ADR014](ADR014-temporal-journal-eventstore-bridge.md) (bridge Temporal journal, distinct du module Magento)
