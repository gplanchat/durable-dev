---
title: Référence de configuration
weight: 35
---

# Référence de configuration

Cette page documente chaque clé acceptée par `DurableBundle` dans `config/packages/durable.yaml`.

---

## Exemple complet

```yaml
durable:
    dbal:                                    # lu seulement si un type ci-dessous vaut 'dbal'
        connection: doctrine.dbal.default_connection
        lock_factory: lock.factory           # doit être partagé entre les workers
    event_store:
        type: in_memory                      # 'in_memory' (défaut) ou 'dbal'
        table_name: durable_events
    temporal:
        dsn: null                            # mettre temporal://… pour activer le backend Temporal
        journal: true                        # false : le cluster est joignable, event_store reste le journal
    workflow_metadata:
        type: in_memory                      # 'in_memory' (défaut) ou 'dbal'
        table_name: durable_workflow_metadata
    activity_transport:
        type: messenger                      # 'in_memory' est le DÉFAUT — mettre 'messenger' pour router
        transport_name: durable_activities
        table_name: durable_activity_outbox
    max_activity_retries: 0                  # réessais automatiques maximum avant de marquer une activité en échec
    activity_contracts:
        cache: cache.app                     # pool de cache PSR-6 pour les métadonnées de contrat (défaut : null, pas de cache)
        contracts:
            - App\Workflow\Activity\OrderActivities
    child_workflow:
        async_messenger: true                # true = les workflows enfants partent par Messenger
        parent_link_store:
            type: in_memory                  # 'in_memory' (défaut) ou 'dbal'
            table_name: durable_child_workflow_parent_link
```

> [!IMPORTANT]
> **`activity_transport.type` vaut `in_memory` par défaut, pas `messenger`.** Omettez la clé et les
> activités s'exécutent **de façon synchrone dans la tâche de workflow**, quel que soit le transport
> défini dans `messenger.yaml`. C'est pour cette raison que tous les exemples de ce site la posent
> explicitement. Voir [`activity_transport`](#activity_transport).

---

## `dbal`

Où le backend SQL prend sa connexion et son verrou. Lu seulement quand l'une des trois clés `type`
ci-dessous vaut `dbal` ; ignoré sinon, le laisser à ses défauts ne coûte donc rien.

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `connection` | identifiant de service | `doctrine.dbal.default_connection` | La `Doctrine\DBAL\Connection` dans laquelle les magasins écrivent. |
| `lock_factory` | identifiant de service | `lock.factory` | La `LockFactory` qui sérialise les reprises d'une même exécution. **Elle ne vaut que ce que vaut votre magasin de verrous** — une fabrique en mémoire ou locale au processus, avec plusieurs workers, vous redonne la panne que le verrou existe pour empêcher. |

Le compromis que fait ce backend, et pourquoi le verrou est porteur, sont sur la page
[Backends](../backends/#le-backend-dbal).

---

## `event_store`

Détermine où l'historique d'événements du workflow est stocké.

| Clé | Valeurs | Défaut | Description |
|-----|---------|--------|-------------|
| `type` | `in_memory`, `dbal` | `in_memory` | Le backend de stockage. `in_memory` garde les événements dans le processus PHP — ce qui convient aux tests et à Temporal natif (Temporal étant la vraie source de l'historique). `dbal` les persiste en SQL, et c'est ce qui fait survivre une exécution à un redémarrage sans cluster. |
| `table_name` | chaîne | `durable_events` | Table dans laquelle le magasin `dbal` écrit. Créée à la première écriture. |

### Avec Temporal

Le stockage d'événements `in_memory` reste correct quand `temporal.dsn` est défini.
`TemporalReadThroughEventStore` l'enveloppe : les événements absents localement sont récupérés à la
demande depuis le gRPC de Temporal (`GetWorkflowExecutionHistory`), de sorte que le DataCollector du
profileur Symfony fonctionne d'un processus à l'autre.

---

## `temporal`

| Clé | Valeurs | Défaut | Description |
|-----|---------|--------|-------------|
| `dsn` | `temporal://hôte:port?…` ou `null` | `null` | À `null` : backend Messenger en mémoire. Défini : active le backend gRPC Temporal (`ext-grpc` requis). |
| `journal` | `true` / `false` | `true` | `false` dit que le cluster est joignable **sans** être le journal : `event_store` reste la source de vérité, et le tableau de bord continue de la lire. C'est ainsi qu'une application dont le journal est DBAL sert une opération Nexus — voir [Opérations Nexus](../nexus/). Poser un DSN avec `journal: true` à côté d'`event_store.type: dbal` est refusé : le journal ne peut pas avoir deux sources de vérité. |

### Format du DSN

```
temporal://HÔTE:PORT?namespace=ESPACE&journal_task_queue=FILE&activity_task_queue=FILE&tls=0|1
```

| Paramètre | Requis | Description |
|-----------|--------|-------------|
| `namespace` | oui | Espace de noms Temporal (par exemple `default`). |
| `journal_task_queue` | oui | File des tâches de workflow (par exemple `durable-journal`). |
| `activity_task_queue` | oui | File des tâches d'activité (par exemple `durable-activities`). |
| `tls` | non (défaut `0`) | `tls=1` pour activer TLS sur la connexion gRPC. |

**Exemple :**
```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
```

Par variable d'environnement :
```yaml
durable:
    temporal:
        dsn: '%env(DURABLE_DSN)%'
```

---

## `workflow_metadata`

Stocke le type de workflow et sa charge utile initiale, retrouvés par `executionId` au moment de la
reprise.

| Clé | Valeurs | Défaut | Description |
|-----|---------|--------|-------------|
| `type` | `in_memory`, `dbal` | `in_memory` | Stockage dans le processus. Correct pour les tests mono-processus et pour Temporal (les métadonnées sont persistées dans l'historique Temporal par le champ mémo). `dbal` les persiste en SQL. |
| `table_name` | chaîne | `durable_workflow_metadata` | Table dans laquelle le magasin `dbal` écrit. Créée à la première écriture. |

---

## `activity_transport`

Comment le bundle achemine les messages d'activité, des tâches de workflow vers les gestionnaires
d'activité.

| Clé | Valeurs | Défaut | Description |
|-----|---------|--------|-------------|
| `type` | `in_memory`, `messenger` | **`in_memory`** | `in_memory` exécute les activités **de façon synchrone dans le gestionnaire de tâche de workflow** — c'est ce que vous obtenez quand la clé est absente. `messenger` route les messages d'activité par Symfony Messenger vers le transport configuré. |
| `transport_name` | chaîne | `durable_activities` | Nom du transport Messenger employé quand `type: messenger`. Doit correspondre à un transport défini dans `messenger.yaml`. |
| `table_name` | chaîne | `durable_activity_outbox` | Nom de la table d'outbox. |

**Le défaut est celui que vous ne voulez probablement pas en production.** Définir `durable_activities`
dans `messenger.yaml` ne le sélectionne pas : sans `type: messenger`, le transport reste vide et
l'activité a déjà tourné en ligne, prenant le temps de la tâche de workflow avec elle et perdant la
sémantique de réessai que le transport apporte.

---

## `max_activity_retries`

```yaml
durable:
    max_activity_retries: 3
```

Plafond sur les réessais automatiques, appliqué aux activités qui n'en posent pas elles-mêmes. `0`
signifie **aucun plafond** — et comme une activité sans `RetryLimit` réessaie indéfiniment (le défaut
de Temporal), laisser les deux non définis revient à ce qu'une activité en échec ne fasse jamais
échouer le workflow. Posez une borne par activité avec `RetryLimit::ofAttempts()` ou
`RetryLimit::once()` ; voir [Options et objets valeur](../options/#retrylimit).

---

## `activity_contracts`

Les métadonnées de contrat d'activité déjà résolues (noms de méthodes, attributs) peuvent être mises
en cache au préchauffage du conteneur, pour éviter le coût de la réflexion à l'exécution.

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `cache` | chaîne (identifiant de service) ou `null` | `null` | Pool de cache PSR-6 à employer. `cache.app` est le pool Symfony par défaut. `null` désactive le cache (utile en environnement `test`). |
| `contracts` | liste de noms de classes pleinement qualifiés | `[]` | Les interfaces de contrat d'activité à préchauffer. |

```yaml
durable:
    activity_contracts:
        cache: cache.app
        contracts:
            - App\Workflow\Activity\OrderActivities
            - App\Workflow\Activity\NotificationActivities
```

---

## `child_workflow`

Contrôle la façon dont les workflows enfants sont lancés.

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `async_messenger` | booléen | `false` | À `true`, les exécutions de workflows enfants partent par Messenger (asynchrone). À `false`, elles tournent de façon synchrone dans la tâche de workflow du parent. |
| `parent_link_store.type` | `in_memory`, `dbal` | `in_memory` | Suit les liens parent → enfant pour propager les complétions. `dbal` les persiste en SQL. |
| `parent_link_store.table_name` | chaîne | `durable_child_workflow_parent_link` | Table dans laquelle le magasin `dbal` écrit. Créée à la première écriture. |

---

## Configuration par environnement (`when@`)

Employez la syntaxe `when@` de Symfony pour changer de backend selon l'environnement :

```yaml
# Toujours en mémoire (défaut pour tous les environnements non redéfinis plus bas)
durable:
    event_store:
        type: in_memory
    temporal:
        dsn: null

# Temporal pour dev et prod
when@dev:
    durable:
        temporal:
            dsn: '%env(DURABLE_DSN)%'

when@prod:
    durable:
        temporal:
            dsn: '%env(DURABLE_DSN)%'

# En mémoire forcé pour les tests (l'emporte sur dev/prod même si DURABLE_DSN est défini)
when@test:
    durable:
        temporal:
            dsn: null
        child_workflow:
            async_messenger: false
```

---

## Voir aussi

- [Backends](../backends/) — en mémoire ou Temporal : mise en place Docker, workers, paramètres du DSN.
- [Premiers pas](../getting-started/) — la configuration du routage Messenger.
- [Tester des workflows](../testing/) — `DurableBundleTestTrait` et la configuration de test en mémoire.
