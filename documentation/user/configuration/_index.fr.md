---
title: Référence de configuration
weight: 35
---

# Référence de configuration

Cette page documente chaque clé acceptée par `DurableBundle` dans `config/packages/durable.yaml`.

---

## Toutes les clés et leur défaut {#exemple-complet}

Produit à partir de l'arbre de configuration du bundle par `bin/console config:dump-reference durable` ;
un test du banc Symfony échoue quand ce bloc et l'arbre divergent. Les commentaires du bloc viennent
du code et restent en anglais ; les sections ci-dessous disent à quoi sert chaque clé.

<!-- generated: bin/console config:dump-reference durable -->
```yaml
# Default configuration for extension with alias: "durable"
durable:

    # Where the journal lives. in_memory: one process. dbal: a SQL database (DUR030). temporal: the cluster at temporal.dsn. dbal with a temporal.dsn keeps the journal in SQL and uses the cluster to serve Nexus. Derived from the deprecated event_store.type and temporal.journal when unset.
    backend:              null # One of "in_memory"; "dbal"; "temporal"

    # DBAL backend: durable execution on a single SQL database, with no orchestration cluster (DUR030).
    dbal:

        # Service id of the Doctrine\DBAL\Connection to use
        connection:           doctrine.dbal.default_connection

        # Create the missing tables on the first write, never inside an open transaction. Set it to false as soon as doctrine/migrations holds the schema: otherwise the two mechanisms write one behind the other. bin/console durable:setup creates them either way.
        auto_setup:           true

        # Service id of the Symfony\Component\Lock\LockFactory that serialises the resumes of one execution
        lock_factory:         lock.factory

        # Accept a per-process lock store (flock, semaphore, in-memory). Only safe with exactly one worker: two workers holding a local lock replay the same execution at once.
        allow_local_lock:     false

        # Seconds a resume lock outlives a worker that died holding it. The pass refreshes it at every message it sends through the bus, so it must exceed the longest step of a pass, or a second worker replays the same execution in parallel.
        lock_ttl:             300.0
    event_store:
        type:                 null # One of "in_memory"; "dbal", Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.event_store.type" option is deprecated: set durable.backend instead.)

        # Table of the dbal journal.
        table_name:           durable_events
    temporal:

        # A temporal://… DSN (for instance %env(DURABLE_DSN)%). When set, it turns on the native Temporal backend (gRPC); over ext-grpc when it is loaded, over curl (ext-curl) otherwise; temporal+http:// for the JSON gateway. No SQL/PDO.
        dsn:                  null

        # Write the DurableWorkflowName and DurableExecutionId search attributes on every start, so the run list can filter by workflow name and execution id. Register both on the namespace before turning this on: a server refuses a start that names an unregistered attribute.
        search_attributes:    false

        # A service id: the application's GuzzleHttp\ClientInterface, which transport=guzzle then uses — its proxy, TLS options and middleware apply to gRPC. Unused by any other transport; null builds a default client.
        guzzle_client:        null

        # A service id: the application's PSR-18 client, which transport=http (the JSON gateway) then uses instead of curl. Unused by any other transport.
        psr18_client:         null

        # A service id implementing both PSR-17 RequestFactoryInterface and StreamFactoryInterface (Guzzle's HttpFactory, nyholm's Psr17Factory). Defaults to psr18_client, which Symfony's Psr18Client satisfies on its own.
        psr17_factory:        null

        # false: the cluster is reachable, but the journal stays the one in event_store. An application serving a Nexus operation from a DBAL journal needs both — and there are not two sources of truth, since event_store says which one it is.
        journal:              null # Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.temporal.journal" option is deprecated: set durable.backend instead.)
    activity_transport:

        # in_memory runs activities inside the workflow task; messenger routes them to transport_name.
        type:                 in_memory # One of "in_memory"; "messenger"
        table_name:           durable_activity_outbox # Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.activity_transport.table_name" option is read nowhere: no outbox table exists. Remove it.)

        # The Messenger transport activities go to when type is messenger.
        transport_name:       durable_activities
    messenger:

        # Ids of the Messenger buses the bundle installs its middleware on (resume lock, profiler). Empty, which is the default, installs them on every bus, and that is the historical behaviour. Naming buses avoids imposing a per-execution lock on the business command bus, which carries no durable message.
        buses:                []
    profiler:

        # Registers the execution trace, the web profiler panel and the observer on the hot path. Defaults to kernel.debug.
        enabled:              '%kernel.debug%'

    # Retry ceiling for every activity; an activity's own limit can only be stricter. 0: no ceiling.
    max_activity_retries: 0
    activity_contracts:

        # PSR-6 cache pool ID for activity contract metadata
        cache:                null

        # Class names of activity contracts to warm at cache warmup
        contracts:            []
    child_workflow:

        # true: child workflows are dispatched through Messenger; false: they run inside the parent task.
        async_messenger:      false
        parent_link_store:
            type:                 null # One of "in_memory"; "dbal", Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.child_workflow.parent_link_store.type" option is deprecated: set durable.backend instead.)

            # Table of the dbal parent links.
            table_name:           durable_child_workflow_parent_link
    workflow_metadata:
        type:                 null # One of "in_memory"; "dbal", Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.workflow_metadata.type" option is deprecated: set durable.backend instead.)

        # Table of the dbal workflow metadata.
        table_name:           durable_workflow_metadata
```
<!-- end generated -->

> [!IMPORTANT]
> **`activity_transport.type` vaut `in_memory` par défaut, pas `messenger`.** Omettez la clé et les
> activités s'exécutent **de façon synchrone dans la tâche de workflow**, quel que soit le transport
> défini dans `messenger.yaml`. C'est pour cette raison que tous les exemples de ce site la posent
> explicitement. Voir [`activity_transport`](#activity_transport).

---

## `backend`

Où vit le journal. Une seule clé, parce que le journal, les métadonnées de workflow et les liens
parents doivent s'accorder : deux sources de vérité pour une même exécution est l'échec que cette
clé exclut.

| Valeur | Journal, métadonnées, liens parents | Requiert |
|--------|--------------------------------------|----------|
| `in_memory` (défaut) | le processus PHP | rien ; tests et démonstrations mono-processus |
| `dbal` | SQL, via [`dbal`](#dbal) | une connexion Doctrine DBAL et un magasin de verrous partagé |
| `temporal` | le cluster à [`temporal.dsn`](#temporal) ; le processus ne garde que la copie des métadonnées et des liens parents que lisent le profileur et `durable:execution:diagnose` | `temporal.dsn` |

`dbal` avec un `temporal.dsn` garde le journal en SQL et n'utilise le cluster que pour servir les
opérations Nexus ; voir [Opérations Nexus](../nexus/).

Une configuration qui se contredit est refusée à la construction du conteneur, avec le chemin
`durable` dans le message : `backend: temporal` sans DSN, ou une clé dépréciée ci-dessous qui dit
autre chose que `backend`.

> [!NOTE]
> `event_store.type`, `workflow_metadata.type`, `child_workflow.parent_link_store.type` et
> `temporal.journal` sont dépréciées depuis 0.1.0-beta1 et seront retirées dans la prochaine version.
> Quand `backend` n'est pas défini, il est déduit d'elles, si bien qu'une configuration existante
> continue de fonctionner et signale une dépréciation. [UPGRADE.md](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md)
> en donne la traduction.

---

## `dbal`

Où le backend SQL prend sa connexion et son verrou. Lu seulement quand `backend` vaut `dbal` ;
ignoré sinon, le laisser à ses défauts ne coûte donc rien.

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `connection` | identifiant de service | `doctrine.dbal.default_connection` | La `Doctrine\DBAL\Connection` dans laquelle les magasins écrivent. |
| `auto_setup` | booléen | `true` | Crée les tables manquantes à la première écriture, jamais dans une transaction ouverte. Passez-la à `false` dès que Doctrine Migrations tient le schéma, pour que les deux ne l'écrivent pas l'un derrière l'autre. `bin/console durable:setup` crée les tables dans tous les cas. |
| `lock_factory` | identifiant de service | `lock.factory` | La `LockFactory` qui sérialise les reprises d'une même exécution. **Elle ne vaut que ce que vaut votre magasin de verrous** : une fabrique en mémoire ou locale au processus, avec plusieurs workers, vous redonne la panne que le verrou existe pour empêcher. |
| `allow_local_lock` | booléen | `false` | Le conteneur refuse un magasin local au processus (`flock`, `semaphore`, `in-memory`, `null`) derrière `lock_factory` : à la compilation pour un DSN littéral, à la première construction du verrou pour un DSN lu dans une variable d'environnement. `true` l'accepte, pour un seul worker. `framework.lock` attend une URL DBAL (`pgsql://…`, `mysql://…`), pas un nom de connexion Doctrine. |
| `lock_ttl` | flottant, secondes | `300` | Combien de temps un verrou de reprise survit à un worker mort en le tenant. La passe qui le tient lui redonne un TTL entier à chaque frontière d'étape, c'est-à-dire à chaque message qu'elle fait passer par le bus, donc **il doit dépasser la plus longue étape** : au-delà, un second worker rejoue la même exécution en parallèle, et le premier s'arrête à sa frontière suivante. Une étape est en général le rejeu du journal jusqu'à la commande suivante, bien moins d'une seconde. Les activités tournent en dehors de la passe, sauf sur un transport d'activités `sync://`, où chacune est une étape de la passe et où sa durée compte. Une passe qui ne fait que rejouer, sans aucun message entre-temps, n'est pas rafraîchie. |

Le compromis que fait ce backend, et pourquoi le verrou est porteur, sont sur la page
[Backends](../backends/#le-backend-dbal).

---

## `event_store`

Détermine où l'historique d'événements du workflow est stocké.

| Clé | Valeurs | Défaut | Description |
|-----|---------|--------|-------------|
| `type` | `in_memory`, `dbal` | déduit de `backend` | **Dépréciée** : posez [`backend`](#backend). |
| `table_name` | chaîne | `durable_events` | Table dans laquelle le magasin `dbal` écrit. Créée à la première écriture. |

### Avec Temporal

Avec `backend: temporal`, le stockage d'événements local est en mémoire, et c'est correct.
`TemporalReadThroughEventStore` l'enveloppe : les événements absents localement sont récupérés à la
demande depuis le gRPC de Temporal (`GetWorkflowExecutionHistory`), de sorte que le DataCollector du
profileur Symfony fonctionne d'un processus à l'autre.

---

## `temporal`

| Clé | Valeurs | Défaut | Description |
|-----|---------|--------|-------------|
| `dsn` | `temporal://hôte:port?…` ou `null` | `null` | Le cluster. Requis par `backend: temporal` ; avec `backend: dbal`, le cluster sert les opérations Nexus et le journal reste en SQL. Toute valeur qui n'est ni une chaîne non vide ni `null` est refusée. Le gRPC passe par `ext-grpc` quand l'extension est chargée, par curl (HTTP/2) sinon ; le schéma choisit le fil, voir plus bas. |
| `journal` | `true` / `false` | déduit de `backend` | **Dépréciée** : `true` équivaut à `backend: temporal`, `false` avec un DSN équivaut à `backend: dbal`. |
| `search_attributes` | `true` / `false` | `false` | Écrit `DurableWorkflowName` et `DurableExecutionId` à chaque démarrage, pour que la liste des exécutions puisse filtrer par nom de workflow et par identifiant d'exécution. [Enregistrez-les sur l'espace de noms](../backends/#register-durables-search-attributes) **avant** de l'activer. Sous Laravel, la même clé de `config/durable.php` ; sous Magento, `durable/temporal/search_attributes` dans `env.php`. |
| `guzzle_client` | un id de service ou `null` | `null` | Le `GuzzleHttp\ClientInterface` de l'application, utilisé par `transport=guzzle` dans le DSN : son proxy, ses options TLS et ses middlewares s'appliquent au gRPC. Ignoré par tout autre transport ; `null` construit un client par défaut. Sous Laravel, la même clé de `config/durable.php` nomme une liaison du conteneur ; sous Magento, c'est l'argument `guzzle` de `RuntimeFactory` dans `di.xml`. |
| `psr18_client` | un id de service ou `null` | `null` | Le client PSR-18 de l'application, utilisé par `transport=http` (la passerelle JSON) à la place de curl. Ignoré par tout autre transport. |
| `psr17_factory` | un id de service ou `null` | `psr18_client` | Un service qui implémente à la fois les factories PSR-17 de requêtes et de flux — le `HttpFactory` de Guzzle, le `Psr17Factory` de nyholm. Le `Psr18Client` de Symfony est à la fois client et factory, d'où la valeur par défaut. Sous Laravel, les deux clés de `config/durable.php` nomment des liaisons du conteneur ; sous Magento, un `Psr18Http` est l'argument `jsonGateway` de `RuntimeFactory` dans `di.xml`. |

### Format du DSN

```
temporal://HÔTE:PORT?namespace=ESPACE&journal_task_queue=FILE&activity_task_queue=FILE
```

Le schéma nomme le fil et le chiffrement :

| Schéma | Fil | TLS | Port par défaut | Demande |
|--------|-----|-----|-----------------|---------|
| `temporal://` | gRPC | non | 7233 | `ext-grpc`, ou `ext-curl` (gRPC sur HTTP/2, choisi de lui-même quand l'extension n'est pas chargée ; le repli est journalisé une fois) |
| `temporal+tls://` | gRPC | oui | 7233 | idem |
| `temporal+http://` | la passerelle JSON du serveur | non | 7243 | `ext-curl` — ou un client PSR-18 remis à la factory — et le port HTTP activé sur le serveur. Appels client seulement : aucun worker ne peut y interroger sa file |
| `temporal+https://` | la passerelle JSON du serveur | oui | 7243 | idem |

| Paramètre | Requis | Description |
|-----------|--------|-------------|
| `namespace` | oui | Espace de noms Temporal (par exemple `default`). |
| `journal_task_queue` | oui | File des tâches de workflow (par exemple `durable-journal`). |
| `activity_task_queue` | oui | File des tâches d'activité (par exemple `durable-activities`). |
| `task_queue` | non | L'ancienne écriture de `journal_task_queue`, lue quand celle-ci est absente. |
| `workflow_task_queue` | non (défaut `durable-workflows`) | File des tâches de workflow de l'application. |
| `nexus_task_queue` | non (défaut : la file des tâches de workflow) | File des tâches Nexus que sert cette application. |
| `workflow_type` | non (défaut `DurableJournal`) | Type de workflow du journal. |
| `identity` | non (défaut `durable-temporal-bridge-php`) | Identité que ce worker annonce au serveur. |
| `tls` | non | `tls=1` est l'ancienne écriture des schémas `+tls` et `+https` ; toujours acceptée. |
| `ca` | non, TLS seulement | Chemin du fichier PEM de l'autorité qui signe le certificat du serveur. Sans lui, le magasin du système fait foi. |
| `cert` | non, TLS seulement | Chemin du fichier PEM d'un certificat client, pour le mTLS. Va avec `key`. |
| `key` | non, TLS seulement | Chemin du fichier PEM de la clé privée de ce certificat. Va avec `cert`. |
| `api_key` | non, TLS seulement | Envoyée à chaque appel en `authorization: Bearer …`, avec un en-tête `temporal-namespace` (clés d'API de Temporal Cloud). À encoder pour l'URL. |
| `transport` | non (défaut `auto`) | Surcharge ce que le schéma implique : `grpc` exige `ext-grpc` et échoue sans elle, `grpc-curl` force curl même quand l'extension est chargée, `guzzle` fait passer le gRPC par Guzzle 7.14 ou plus (son handler cURL lit les trailers), `http` est ce que `temporal+http://` pose. `auto` prend `grpc` si l'extension est chargée, `grpc-curl` sinon. |

Toute autre clé est refusée, et nommée : une coquille comme `namesapce=` ne retombe plus en silence
sur l'espace de noms `default`. De même pour `ca`, `cert`, `key` ou `api_key` sans TLS. Avec un
client PSR-18 remis à la passerelle JSON, le TLS relève de la configuration de ce client : `ca`,
`cert` et `key` y sont refusés.

**Exemple :**
```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities
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
| `type` | `in_memory`, `dbal` | déduit de `backend` | **Dépréciée** : posez [`backend`](#backend). |
| `table_name` | chaîne | `durable_workflow_metadata` | Table dans laquelle le magasin `dbal` écrit. Créée à la première écriture. |

---

## `activity_transport`

Comment le bundle achemine les messages d'activité, des tâches de workflow vers les gestionnaires
d'activité.

| Clé | Valeurs | Défaut | Description |
|-----|---------|--------|-------------|
| `type` | `in_memory`, `messenger` | **`in_memory`** | `in_memory` exécute les activités **de façon synchrone dans le gestionnaire de tâche de workflow**, c'est ce que vous obtenez quand la clé est absente. `messenger` route les messages d'activité par Symfony Messenger vers le transport configuré. |
| `transport_name` | chaîne | `durable_activities` | Nom du transport Messenger employé quand `type: messenger`. Doit correspondre à un transport défini dans `messenger.yaml`. |
| `table_name` | chaîne | `durable_activity_outbox` | **Dépréciée**, lue nulle part : aucune table d'outbox n'existe. Retirez-la. |

**Le défaut est celui que vous ne voulez probablement pas en production.** Définir `durable_activities`
dans `messenger.yaml` ne le sélectionne pas : sans `type: messenger`, le transport reste vide et
l'activité a déjà tourné en ligne, prenant le temps de la tâche de workflow avec elle et perdant la
sémantique de réessai que le transport apporte.

---

## `messenger`

```yaml
durable:
    messenger:
        buses:
            - messenger.bus.durable
```

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `buses` | liste d'identifiants de service de bus | `[]` | Les bus sur lesquels vont les middlewares du bundle ; `[]` signifie tous les bus. Voir ci-dessous. |

Les bus Messenger sur lesquels le bundle installe ses middlewares — le verrou de reprise DBAL, et
le middleware de profil en debug.

**Le défaut est tous les bus**, ce que les versions précédentes faisaient sans condition. Ce défaut
ne peut pas être plus fin : le bundle ne sait pas vers quel bus votre application route
`ResumeWorkflowMessage`, et deviner retirerait le verrou de reprise du bus qui porte réellement le
travail — une perte silencieuse de la garantie pour laquelle ce verrou existe.

Nommer les bus vaut la peine dès que vous en avez plusieurs. Un bus de commandes métier ne
transporte aucun message durable, et y prendre un verrou par exécution est une contention que
personne n'a demandée. Un identifiant qui ne nomme aucun bus déclaré est refusé à la compilation,
plutôt que de ne rien faire en silence.

---

## `profiler`

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `enabled` | booléen | `%kernel.debug%` | Enregistre la trace d'exécution, le panneau du profileur web et l'observateur sur le chemin critique de l'exécution. Désactivé, l'observateur est un objet nul. |

---

## `max_activity_retries`

```yaml
durable:
    max_activity_retries: 3
```

Plafond sur les réessais automatiques, appliqué à chaque activité : la `RetryLimit` propre à une
activité ne peut qu'être plus stricte. Une valeur négative est refusée. `0` signifie **aucun plafond**, et comme une activité sans
`RetryLimit` réessaie indéfiniment (le défaut de Temporal), laisser les deux non définis revient à
ce qu'une activité en échec ne fasse jamais échouer le workflow. Posez une borne par activité avec
`RetryLimit::ofAttempts()` ou `RetryLimit::once()` ; voir [Options et objets valeur](../options/#retrylimit).

---

## `activity_contracts`

Les métadonnées de contrat d'activité déjà résolues (noms de méthodes, attributs) peuvent être mises
en cache au préchauffage du conteneur, pour éviter le coût de la réflexion à l'exécution.

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `cache` | chaîne (identifiant de service) ou `null` | `null` | Pool de cache PSR-6 à employer. `cache.app` est le pool Symfony par défaut. `null` désactive le cache (utile en environnement `test`). |
| `contracts` | liste de noms de classes pleinement qualifiés | `[]` | Les interfaces de contrat d'activité à préchauffer. Un nom que l'autoloader ne trouve pas comme interface est refusé à la construction du conteneur. |

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
| `parent_link_store.type` | `in_memory`, `dbal` | déduit de `backend` | **Dépréciée** : posez [`backend`](#backend). |
| `parent_link_store.table_name` | chaîne | `durable_child_workflow_parent_link` | Table dans laquelle le magasin `dbal` écrit. Créée à la première écriture. |

---

## Configuration par environnement (`when@`)

Employez la syntaxe `when@` de Symfony pour changer de backend selon l'environnement :

```yaml
# En mémoire pour chaque environnement non redéfini plus bas
durable:
    backend: in_memory

# Temporal pour dev et prod
when@dev:
    durable:
        backend: temporal
        temporal:
            dsn: '%env(DURABLE_DSN)%'

when@prod:
    durable:
        backend: temporal
        temporal:
            dsn: '%env(DURABLE_DSN)%'

# En mémoire pour les tests, même si DURABLE_DSN est défini
when@test:
    durable:
        child_workflow:
            async_messenger: false
```

---

## Voir aussi

- [Backends](../backends/) compare la mémoire et Temporal : mise en place Docker, workers, paramètres du DSN.
- [Premiers pas](../getting-started/) couvre la configuration du routage Messenger.
- [Tester des workflows](../testing/) couvre `DurableBundleTestTrait` et la configuration de test en mémoire.
