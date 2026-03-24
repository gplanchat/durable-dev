# Intégration Symfony Messenger

ADR005-messenger-integration
===

Introduction
---

Ce **Architecture Decision Record** décide d'utiliser **Symfony Messenger** comme transport pour les activités du projet Durable, et comme file pour les **reprises de workflow** en mode distribué. Cette décision permet une exécution distribuée sans dépendance à RoadRunner, en s'appuyant sur l'écosystème Symfony existant.

Contexte
---

Le projet Durable doit permettre l'exécution d'activités de manière asynchrone et distribuée. Les alternatives considérées incluaient :

- **RoadRunner + Temporal** : impose RoadRunner, complexité élevée
- **Laravel Queues** : non applicable (projet Symfony)
- **Symfony Messenger** : natif Symfony, support Redis, Dbal, SQS, etc.
- **Doctrine DBAL** : simple, sans dépendance externe (transport Dbal)

Décision
---

Symfony Messenger est utilisé comme **transport principal** pour les messages d'activité (`ActivityMessage`) et, lorsque `durable.distributed` est activé, pour les messages **`WorkflowRunMessage`** (démarrage / reprise de run). Un transport Dbal et un transport InMemory sont également fournis pour les tests et les déploiements légers.

Implémentation
---

### MessengerActivityTransport

Adapte l'interface `ActivityTransportInterface` aux primitives Messenger :

- `enqueue()` → `SenderInterface::send()`
- `dequeue()` → `ReceiverInterface::get()` puis `ack()`

### Configuration (bundle)

```yaml
# config/packages/durable.yaml
durable:
    distributed: true
    activity_transport:
        type: messenger
        transport_name: durable_activities
```

Le routage des messages (`WorkflowRunMessage`, `ActivityMessage`, etc.) vers les bons transports se fait dans **`config/packages/messenger.yaml`** (noms typiques : `durable_workflows`, `durable_activities`).

### Worker activités

La commande **`durable:activity:consume`** consomme les messages depuis le transport configuré, exécute les activités via **`ActivityExecutor`**, et persiste les résultats dans l’**EventStore**.

### Côté workflow (rappel)

Les handlers enregistrés sont des **classes** `#[Workflow]` dont la méthode **`#[WorkflowMethod]`** reçoit **`WorkflowEnvironment`** : les appels d’activité passent par des **stubs typés** (`activityStub()`), qui sous-tendent toujours l’enqueue via Messenger lorsque le transport d’activités est Messenger ([ADR004](ADR004-ports-and-adapters.md), [OST003](../ost/OST003-activity-api-ergonomics.md)).

Modèle distribué
---

- **Mode inline** : workflow et activités dans le même process (`InMemoryWorkflowRunner`, **`drainActivityQueueOnce`**)
- **Mode distribué** : activités consommées par des workers ; workflows consommés par **`WorkflowRunHandler`** sur un transport dédié ; **EventStore** et éventuellement **métadonnées de reprise** partagés (Dbal) — voir [ADR007](ADR007-workflow-recovery.md) et [ADR009](ADR009-distributed-workflow-dispatch.md)

Références
---

- [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
- [ADR004 - Ports et Adapters](ADR004-ports-and-adapters.md)
- [ADR007 - Reprise et recovery](ADR007-workflow-recovery.md)
- [ADR009 - Re-dispatch workflow](ADR009-distributed-workflow-dispatch.md)
