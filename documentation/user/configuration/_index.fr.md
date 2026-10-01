---
title: Référence de configuration
weight: 35
---

# Référence de configuration

Cette page documente chaque clé que `DurableBundle` accepte dans `config/packages/durable.yaml`.

Termes employés plus bas : une exécution est un déroulement durable d'un workflow ; le journal est
l'enregistrement, en ajout seul, de tout ce qu'une exécution a décidé et reçu ; une activité est une
unité d'effet de bord, comme un appel HTTP ou une écriture en base ; un worker est le processus qui
rejoue les workflows et exécute les activités ; le rejeu est la façon dont une exécution reprend, en
réexécutant le code du workflow depuis sa première ligne face au journal. Le
[glossaire](../glossary/) définit chacun de ces termes.

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

        # A service id: the application's PayloadCodecInterface, which encodes every payload sent to Temporal and decodes every payload read (DUR055). The codec holds its own key, from the application's secrets or environment; Durable reads none. null sends payloads as they are.
        payload_codec:        null

        # false: the cluster is reachable, but the journal stays the one in event_store. An application serving a Nexus operation from a DBAL journal needs both — and there are not two sources of truth, since event_store says which one it is.
        journal:              null # Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.temporal.journal" option is deprecated: set durable.backend instead.)
    activity_transport:

        # in_memory runs activities inside the workflow task; messenger routes them to transport_name.
        type:                 in_memory # One of "in_memory"; "messenger"

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
> **La valeur par défaut de `activity_transport.type` est `in_memory`.** Si vous omettez la clé,
> les activités s'exécutent **de façon synchrone dans la tâche de workflow**, quel que soit le
> transport défini dans `messenger.yaml`. C'est pourquoi tous les exemples de ce site posent la clé
> explicitement. Voir [`activity_transport`](#activity_transport).

---

## `backend`

Où vit le journal. Une seule clé fixe le stockage du journal, des métadonnées de workflow et des
liens parents, pour que les trois concordent et qu'une même exécution n'ait jamais deux sources de
vérité.

| Valeur | Journal, métadonnées, liens parents | Requiert |
|--------|--------------------------------------|----------|
| `in_memory` (défaut) | le processus PHP | rien ; tests et démonstrations mono-processus |
| `dbal` | SQL, via [`dbal`](#dbal) | une connexion Doctrine DBAL et un magasin de verrous partagé |
| `temporal` | le cluster à [`temporal.dsn`](#temporal) ; le processus ne garde que la copie des métadonnées et des liens parents que lisent le profileur et `durable:execution:diagnose` | `temporal.dsn` |

`dbal` avec un `temporal.dsn` garde le journal en SQL et n'utilise le cluster que pour servir les
opérations Nexus (des opérations servies par un autre service, qu'un workflow appelle comme une
activité) ; voir [Opérations Nexus](../nexus/).

Quand la configuration se contredit, la construction du conteneur échoue, avec le chemin `durable`
dans le message. Deux cas la déclenchent : `backend: temporal` sans DSN, et une clé dépréciée
ci-dessous qui dit autre chose que `backend`.

> [!NOTE]
> `event_store.type`, `workflow_metadata.type`, `child_workflow.parent_link_store.type` et
> `temporal.journal` sont dépréciées depuis 0.1.0-beta1 et seront retirées dans la prochaine version.
> Quand `backend` n'est pas défini, le bundle le déduit d'elles : une configuration existante
> continue donc de fonctionner et signale une dépréciation. [UPGRADE.md](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md)
> en donne la traduction.

---

## `dbal`

La connexion et le verrou du backend SQL. Le bundle ne lit cette section que quand `backend` vaut
`dbal` et l'ignore sinon : la laisser à ses valeurs par défaut ne coûte donc rien.

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `connection` | identifiant de service | `doctrine.dbal.default_connection` | La `Doctrine\DBAL\Connection` dans laquelle les magasins écrivent. Donnez-leur une connexion à eux. Partager celle de l'application est fortement déconseillé (DUR054), car les transactions de Durable s'imbriquent alors dans les transactions métier. |
| `auto_setup` | booléen | `true` | Crée les tables manquantes à la première écriture, jamais dans une transaction ouverte. Passez-la à `false` dès que Doctrine Migrations tient le schéma, pour qu'un seul des deux l'écrive. `bin/console durable:setup` crée les tables dans tous les cas. |
| `lock_factory` | identifiant de service | `lock.factory` | La `LockFactory` qui sérialise les reprises d'une même exécution. **Le verrou ne protège les reprises que si tous les workers partagent le magasin de verrous.** Avec plusieurs workers, une fabrique en mémoire ou locale au processus laisse deux workers rejouer la même exécution en même temps, la panne que le verrou empêche. |
| `allow_local_lock` | booléen | `false` | À `false`, un magasin local au processus (`flock`, `semaphore`, `in-memory`, `null`) derrière `lock_factory` lève une erreur : à la compilation pour un DSN littéral, à la première construction du verrou pour un DSN lu dans une variable d'environnement. `true` accepte un tel magasin, pour un seul worker. `framework.lock` attend une URL DBAL (`pgsql://…`, `mysql://…`) ; un nom de connexion Doctrine n'y est pas accepté. |
| `lock_ttl` | flottant, secondes | `300` | Combien de temps un verrou de reprise survit à un worker mort en le tenant. La passe qui le tient lui redonne un TTL entier à chaque frontière d'étape, c'est-à-dire à chaque message qu'elle fait passer par le bus, donc **le TTL doit dépasser la plus longue étape**. Quand une étape le dépasse, un second worker rejoue la même exécution en parallèle, et le premier s'arrête à sa frontière suivante. Une étape est en général le rejeu du journal jusqu'à la commande suivante, bien moins d'une seconde. Les activités tournent en dehors de la passe, sauf sur un transport d'activités `sync://`, où chacune est une étape de la passe et où sa durée compte. Une passe qui ne fait que rejouer, sans aucun message entre-temps, n'est pas rafraîchie. |

La page [Backends](../backends/#le-backend-dbal) décrit le compromis que fait ce backend et
pourquoi il dépend du verrou.

---

## `event_store`

Où l'historique d'événements du workflow est stocké.

| Clé | Valeurs | Défaut | Description |
|-----|---------|--------|-------------|
| `type` | `in_memory`, `dbal` | déduit de `backend` | **Dépréciée** : posez [`backend`](#backend). |
| `table_name` | chaîne | `durable_events` | Table dans laquelle le magasin `dbal` écrit. Créée à la première écriture. |

### Stockage d'événements local sur le backend Temporal {#avec-temporal}

Avec `backend: temporal`, le stockage d'événements local est en mémoire, ce qui est la configuration
attendue. `TemporalReadThroughEventStore` l'enveloppe et récupère à la demande, par le gRPC de
Temporal (`GetWorkflowExecutionHistory`), les événements absents localement : le DataCollector du
profileur Symfony fonctionne ainsi d'un processus à l'autre.

---

## `temporal`

| Clé | Valeurs | Défaut | Description |
|-----|---------|--------|-------------|
| `dsn` | `temporal://hôte:port?…` ou `null` | `null` | Le cluster. Requis par `backend: temporal` ; avec `backend: dbal`, le cluster sert les opérations Nexus et le journal reste en SQL. Toute valeur qui n'est ni une chaîne non vide ni `null` lève une erreur de configuration. Le gRPC passe par `ext-grpc` quand l'extension est chargée, par curl (HTTP/2) sinon ; le schéma choisit le fil, voir plus bas. |
| `journal` | `true` / `false` | déduit de `backend` | **Dépréciée** : `true` équivaut à `backend: temporal`, `false` avec un DSN équivaut à `backend: dbal`. |
| `search_attributes` | `true` / `false` | `false` | Écrit `DurableWorkflowName` et `DurableExecutionId` à chaque démarrage, pour que la liste des exécutions puisse filtrer par nom de workflow et par identifiant d'exécution. [Enregistrez-les sur l'espace de noms](../backends/#register-durables-search-attributes) **avant** de l'activer. Sous Laravel, la même clé de `config/durable.php` ; sous Magento, `durable/temporal/search_attributes` dans `env.php`. |
| `guzzle_client` | un id de service ou `null` | `null` | Le `GuzzleHttp\ClientInterface` de l'application, utilisé par `transport=guzzle` dans le DSN : son proxy, ses options TLS et ses middlewares s'appliquent au gRPC. Ignoré par tout autre transport ; `null` construit un client par défaut. Sous Laravel, la même clé de `config/durable.php` nomme une liaison du conteneur ; sous Magento, c'est l'argument `guzzle` de `RuntimeFactory` dans `di.xml`. |
| `psr18_client` | un id de service ou `null` | `null` | Le client PSR-18 de l'application, utilisé par `transport=http` (la passerelle JSON) à la place de curl. Ignoré par tout autre transport. |
| `psr17_factory` | un id de service ou `null` | `psr18_client` | Un service qui implémente à la fois les factories PSR-17 de requêtes et de flux, comme le `HttpFactory` de Guzzle ou le `Psr17Factory` de nyholm. Le `Psr18Client` de Symfony est à la fois client et factory, d'où la valeur par défaut. Sous Laravel, les deux clés de `config/durable.php` nomment des liaisons du conteneur ; sous Magento, un `Psr18Http` est l'argument `jsonGateway` de `RuntimeFactory` dans `di.xml`. |
| `payload_codec` | un id de service ou `null` | `null` | Le `PayloadCodecInterface` de l'application, qui encode chaque payload envoyé à Temporal et décode chaque payload lu ([DUR055](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR055-a-payload-codec-at-the-client-boundary.md)). Le codec lit sa propre clé, dans les secrets Symfony ou l'environnement ; Durable n'en lit aucune. |

### Format du DSN

```
temporal://HÔTE:PORT?namespace=ESPACE&journal_task_queue=FILE&activity_task_queue=FILE
```

Le schéma fixe le protocole de transport et le chiffrement :

| Schéma | Fil | TLS | Port par défaut | Demande |
|--------|-----|-----|-----------------|---------|
| `temporal://` | gRPC | non | 7233 | `ext-grpc`, ou `ext-curl` (gRPC sur HTTP/2, choisi automatiquement quand l'extension n'est pas chargée ; le repli est journalisé une fois) |
| `temporal+tls://` | gRPC | oui | 7233 | idem |
| `temporal+http://` | la passerelle JSON du serveur | non | 7243 | `ext-curl` (ou un client PSR-18 remis à la factory) et le port HTTP activé sur le serveur. Appels client seulement ; aucun worker ne peut y interroger sa file |
| `temporal+https://` | la passerelle JSON du serveur | oui | 7243 | idem |

| Paramètre | Requis | Description |
|-----------|--------|-------------|
| `namespace` | oui | Espace de noms Temporal (par exemple `default`). |
| `journal_task_queue` | oui | File des tâches de workflow (par exemple `durable-journal`). |
| `activity_task_queue` | oui | File des tâches d'activité (par exemple `durable-activities`). |
| `task_queue` | non | L'ancienne écriture de `journal_task_queue`, lue quand celle-ci est absente. |
| `workflow_task_queue` | non (défaut `durable-workflows`) | File des tâches de workflow de l'application. |
| `nexus_task_queue` | non (défaut : la file des tâches de workflow) | File des tâches Nexus que sert cette application. |
| `identity` | non (défaut `durable-temporal-bridge-php`) | Identité que ce worker annonce au serveur. |
| `tls` | non | `tls=1` est l'ancienne écriture des schémas `+tls` et `+https` ; toujours acceptée. |
| `ca` | non, TLS seulement | Chemin du fichier PEM de l'autorité qui signe le certificat du serveur. Sans lui, le magasin du système fait foi. |
| `cert` | non, TLS seulement | Chemin du fichier PEM d'un certificat client, pour le mTLS. Va avec `key`. |
| `key` | non, TLS seulement | Chemin du fichier PEM de la clé privée de ce certificat. Va avec `cert`. |
| `api_key` | non, TLS seulement | Envoyée à chaque appel en `authorization: Bearer …`, avec un en-tête `temporal-namespace` (clés d'API de Temporal Cloud). À encoder pour l'URL. |
| `transport` | non (défaut `auto`) | Surcharge ce que le schéma implique : `grpc` exige `ext-grpc` et échoue sans elle, `grpc-curl` force curl même quand l'extension est chargée, `guzzle` fait passer le gRPC par Guzzle 7.14 ou plus (son handler cURL lit les trailers), `http` est ce que `temporal+http://` pose. `auto` prend `grpc` si l'extension est chargée, `grpc-curl` sinon. |

Tout autre paramètre lève une erreur qui le nomme : une coquille comme `namesapce=` ne retombe donc
plus en silence sur l'espace de noms `default`. `ca`, `cert`, `key` ou `api_key` sans TLS lèvent aussi
une erreur. Avec un client PSR-18 remis à la passerelle JSON, le TLS relève de la configuration de ce
client, et `ca`, `cert` et `key` lèvent une erreur.

**Exemple :**
```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities
```

Pour lire le DSN dans une variable d'environnement :
```yaml
durable:
    temporal:
        dsn: '%env(DURABLE_DSN)%'
```

---

## `workflow_metadata`

Où sont stockés le type de workflow et sa charge utile initiale. Le bundle les retrouve par
`executionId` quand il reprend une exécution.

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

**En production, vous ne voulez probablement pas la valeur par défaut.** Définir
`durable_activities` dans `messenger.yaml` ne le sélectionne pas. Sans `type: messenger`, le
transport reste vide et l'activité s'exécute en ligne, sur le temps de la tâche de workflow et sans
la sémantique de réessai que le transport apporte.

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

Les bus Messenger sur lesquels le bundle installe ses middlewares : le verrou de reprise DBAL, et
le middleware de profil en debug.

**Le défaut est tous les bus**, ce que les versions précédentes faisaient sans condition. Un défaut
plus fin n'est pas possible, car rien n'indique au bundle vers quel bus votre application
route `ResumeWorkflowMessage`. Un mauvais choix retirerait le verrou de reprise du bus qui porte le
travail, et les reprises perdraient la protection du verrou sans aucune erreur.

Nommer les bus vaut la peine dès que votre application en a plusieurs. Un bus de commandes métier ne
transporte aucun message durable, et un verrou par exécution sur ce bus ajoute une contention
inutile. Un identifiant qui ne nomme aucun bus déclaré lève une erreur à la compilation au lieu de
ne rien faire en silence.

---

## `profiler`

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `enabled` | booléen | `%kernel.debug%` | Enregistre la trace d'exécution, le panneau du profileur web et l'observateur sur le chemin critique de l'exécution. Désactivé, l'observateur est un objet nul. |

Le panneau affiche ce qu'attend chaque exécution tracée, lu dans le catalogue des exécutions. Sur
Temporal, cela coûte un appel `DescribeWorkflowExecution` par exécution de la requête profilée, deux
pour une exécution que Durable n'a pas démarrée.

---

## `max_activity_retries`

```yaml
durable:
    max_activity_retries: 3
```

Plafond sur les réessais automatiques, appliqué à chaque activité sur les backends `in_memory` et `dbal` : la `RetryLimit` propre à une
activité ne peut qu'être plus stricte. Une valeur négative lève une erreur de configuration. `0` signifie **aucun plafond**, et comme une activité sans
`RetryLimit` réessaie indéfiniment (le défaut de Temporal), laisser les deux non définis revient à
ce qu'une activité en échec ne fasse jamais échouer le workflow. Posez une borne par activité avec
`RetryLimit::ofAttempts()` ou `RetryLimit::once()` ; voir [Options et objets valeur](../options/#retrylimit). Sur le backend `temporal`, aucun hôte ne le lit : la grappe relance d'après la
`RetryLimit` propre à chaque activité.

Sous Laravel, la même clé dans `config/durable.php` ; sous Magento, l'argument `maxActivityRetries`
de `RuntimeFactory` dans `di.xml`, que seul `MagentoRuntime::run()` lit, et seulement sans DSN (voir
[le tableau des hôtes](#host-table)).

---

## `activity_contracts`

Le bundle peut mettre en cache, au préchauffage du conteneur, les métadonnées de contrat d'activité
déjà résolues (noms de méthodes, attributs), ce qui évite le coût de la réflexion à l'exécution.

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `cache` | chaîne (identifiant de service) ou `null` | `null` | Pool de cache PSR-6 à employer. `cache.app` est le pool Symfony par défaut. `null` désactive le cache (utile en environnement `test`). |
| `contracts` | liste de noms de classes pleinement qualifiés | `[]` | Les interfaces de contrat d'activité à préchauffer. Un nom que l'autoloader ne trouve pas comme interface fait échouer la construction du conteneur. |

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

La façon dont les workflows enfants, des exécutions démarrées par une autre exécution, sont lancés.

| Clé | Type | Défaut | Description |
|-----|------|--------|-------------|
| `async_messenger` | booléen | `false` | À `true`, les exécutions de workflows enfants partent par Messenger (asynchrone). À `false`, elles tournent de façon synchrone dans la tâche de workflow du parent. |
| `parent_link_store.type` | `in_memory`, `dbal` | déduit de `backend` | **Dépréciée** : posez [`backend`](#backend). |
| `parent_link_store.table_name` | chaîne | `durable_child_workflow_parent_link` | Table dans laquelle le magasin `dbal` écrit. Créée à la première écriture. |

---

## Configuration par environnement (`when@`)

Pour changer de backend selon l'environnement, employez la syntaxe `when@` de Symfony :

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

## Les mêmes réglages sous Laravel et Magento {#host-table}

Une ligne par réglage. La dernière colonne est une **proposition** en cours de revue (#357) :
*identique* (le réglage existe sur chaque hôte qui peut s'en servir), *propre à l'hôte* (avec la
raison), ou *à ajouter*. Magento n'atteint que deux journaux, en mémoire et Temporal : les lignes
SQL ne s'y appliquent pas.

| Symfony (`durable.yaml`) | Laravel (`config/durable.php`) | Magento (`env.php`, `di.xml`) | Proposition |
|---|---|---|---|
| `backend` | `backend` (`illuminate`, `temporal`, `memory`) | un DSN veut dire Temporal, aucun veut dire en mémoire : l'argument `temporalDsn` dans `di.xml`, sinon `durable/temporal/dsn` dans `env.php` | identique ; la valeur SQL porte le nom de la connexion de chaque hôte |
| `dbal.connection` | `connection` | aucun | identique |
| `dbal.auto_setup` | aucun (le pont livre des migrations) | aucun | propre à l'hôte : Laravel crée les tables par `php artisan migrate` |
| `dbal.lock_factory`, `dbal.allow_local_lock` | `lock.store` | aucun | propre à l'hôte : Symfony Lock et les verrous de cache de Laravel sont deux services différents |
| `dbal.lock_ttl` | `lock.ttl` | aucun | identique |
| aucun | `lock.backoff`, `lock.max_deferrals`, `lock.wait` | aucun | propre à l'hôte : Laravel rend à la file une reprise dont le tour est pris ; le worker Symfony attend que le verrou se libère |
| `event_store.table_name`, `workflow_metadata.table_name`, `child_workflow.parent_link_store.table_name` | `tables.events`, `tables.metadata`, `tables.parent_links`, `tables.runs` | aucun | à ajouter : le nom de la table des exécutions sous Symfony |
| `temporal.dsn` | `temporal.dsn` | argument `temporalDsn`, qui l'emporte sur `durable/temporal/dsn` | identique |
| `temporal.search_attributes` | `temporal.search_attributes` | `durable/temporal/search_attributes` | identique |
| `temporal.guzzle_client`, `temporal.psr18_client`, `temporal.psr17_factory` | les trois mêmes clés | arguments `guzzle`, `jsonGateway` | identique |
| `temporal.payload_codec` | `temporal.payload_codec`, une liaison du conteneur ; le codec lit sa clé dans `.env` | argument `codec` de `RuntimeFactory`, dans le `di.xml` de la boutique ; le codec lit sa clé dans `env.php` | identique (DUR055) |
| `backend: dbal` avec un `temporal.dsn` (servir Nexus depuis un journal SQL) | aucun (`nexus.handlers` exige `backend: temporal`) | aucun | à ajouter sous Laravel |
| `activity_transport.type`, `activity_transport.transport_name` | `queue.connection`, `queue.name` | aucun (les activités tournent dans le processus, ou sur la file de tâches de Temporal) | propre à l'hôte : la file de chaque hôte |
| `messenger.buses` | aucun | aucun | propre à l'hôte : Messenger seulement |
| `profiler.enabled` | aucun | aucun | propre à l'hôte : le profileur web de Symfony |
| `max_activity_retries` | `max_activity_retries` | argument `maxActivityRetries`, lu par `MagentoRuntime::run()` sans DSN seulement ; les workers Temporal l'ignorent | identique sous Symfony et Laravel ; propre à l'hôte sous Magento, dont les workers laissent les tentatives à la grappe. Sous Temporal, aucun hôte ne le lit |
| aucun | aucun | argument `budgetSeconds` | propre à l'hôte : borne `MagentoRuntime::run()`, l'exécution dans le processus sans DSN et l'attente du résultat de la grappe avec un DSN |
| `activity_contracts.cache`, `activity_contracts.contracts` | aucun | aucun | à ajouter sous Laravel et Magento |
| `child_workflow.async_messenger` | aucun | aucun | propre à l'hôte : Messenger seulement |
| workflows : `#[AsWorkflow]` sur un service | `workflows` | argument `workflowClasses` | propre à l'hôte : aucun des deux conteneurs ne s'autoconfigure par attribut |
| gestionnaires d'activités : `#[AsActivityHandler]` sur un service | `activity_handlers` : les classes des gestionnaires, chacune servant le contrat que nomme son `#[AsActivityHandler]`, ou à défaut ses interfaces aux méthodes `#[AsActivityMethod]` | argument `activityHandlers` | propre à l'hôte : aucun des deux conteneurs ne s'autoconfigure par attribut ; Laravel échoue au démarrage sur un gestionnaire qui ne sert aucune activité |
| gestionnaires Nexus : `#[AsNexusServiceHandler]` sur un service | `nexus.handlers` : `gestionnaire => contrat`, ou la classe du gestionnaire seule quand son `#[AsNexusServiceHandler]` nomme le contrat | argument `nexusHandlers` ; le `#[AsNexusServiceHandler]` du gestionnaire nomme le contrat | propre à l'hôte : aucun des deux conteneurs ne s'autoconfigure par attribut |

---

## Voir aussi

- [Backends](../backends/) compare la mémoire et Temporal : mise en place Docker, workers, paramètres du DSN.
- [Premiers pas](../getting-started/) couvre la configuration du routage Messenger.
- [Tester des workflows](../testing/) couvre `DurableBundleTestTrait` et la configuration de test en mémoire.
