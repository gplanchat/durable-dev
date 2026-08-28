---
title: Backends
weight: 15
---

# Backends

Durable prend en charge trois backends d'exécution.

| Backend | Usage |
|---------|-------|
| **En mémoire** | Tests unitaires, tests fonctionnels, exploration locale — aucun serveur nécessaire. |
| **DBAL** | Production sans cluster d'orchestration — une base SQL, pas d'`ext-grpc`. |
| **Temporal** | Production et recette à l'échelle, tests d'intégration réalistes — `ext-grpc` et un cluster Temporal requis. |

> [!NOTE]
> **Sur Magento, la ligne SQL n'existe pas.** `gplanchat/durable-magento` déclare un `conflict`
> Composer sur les deux ponts SQL : `Magento\Framework\App\ResourceConnection` n'est ni une
> connexion Doctrine DBAL ni celle d'Illuminate, donc aucun des deux n'a de quoi se lier. L'état vit
> dans une grappe Temporal, ou il vit dans un processus — et le choix se fait par la présence de
> `durable/temporal/dsn` dans `app/etc/env.php`, pas par un réglage.

Les trois font tourner le **même pilote à fibres** et le même code de workflows et d'activités. Le
choix se fait par `durable.event_store.type` (et `DURABLE_DSN` pour Temporal).

---

## Le backend en mémoire

Le backend en mémoire tourne entièrement dans un seul processus PHP. Aucun serveur externe, aucun
gRPC, et aucune persistance d'une requête à l'autre.

### Comment il fonctionne

- Les messages de workflow et d'activité passent par des transports **Symfony Messenger** en mémoire.
- L'historique d'événements vit dans un `InMemoryEventStore`.
- La vidange Messenger traite les messages de façon synchrone quand vous appelez
  `drainMessengerUntilSettled()` ou son équivalent.

### Configuration

```yaml
# config/packages/durable.yaml (ou when@test:)
durable:
    event_store:
        type: in_memory
    temporal:
        dsn: null
    workflow_metadata:
        type: in_memory
    activity_transport:
        type: messenger
        transport_name: durable_activities

# config/packages/messenger.yaml (ou when@test:)
framework:
    messenger:
        transports:
            durable_workflows:  'in-memory://'
            durable_activities: 'in-memory://'
        routing:
            Gplanchat\Durable\Transport\ResumeWorkflowMessage: durable_workflows
            Gplanchat\Durable\Transport\ActivityMessage:       durable_activities
```

### Quand l'employer

- Pour tous les **tests unitaires et fonctionnels** (voir [Tester des workflows](../testing/)).
- En **développement local**, quand vous n'avez besoin ni de l'historique durable ni de l'interface
  de Temporal.
- Pour les **jobs d'intégration continue** qui tournent sans Docker.

---

## Le backend Temporal

Le backend Temporal délègue l'orchestration à un vrai cluster **Temporal**. Le processus PHP
communique en **gRPC**, via `ext-grpc`.

### Comment il fonctionne

1. Quand `DURABLE_DSN` est défini, `DurableExtension` enregistre les services propres à Temporal
   (`WorkflowClient`, `TemporalHistoryCursor`, les workers).
2. Démarrer un workflow appelle le gRPC `StartWorkflowExecution` sur Temporal.
3. Les **tâches de workflow** sont récupérées par le consommateur Messenger
   `durable_temporal_journal`.
4. Les **tâches d'activité** sont récupérées par le consommateur `durable_temporal_activity`.
5. Chaque tâche de workflow rejoue l'historique via le `WorkflowTaskRunner` à fibres et renvoie ses
   commandes à Temporal.

### Prérequis

- L'extension PHP **`ext-grpc`**, compilée contre la version du paquet `grpc/grpc` qu'exige le pont.
- Un cluster Temporal en marche.

### Installer `ext-grpc`

```bash
pecl install grpc
# À ajouter dans php.ini : extension=grpc
```

Vérification :

```bash
php -m | grep grpc
```

**Dans une image de conteneur, ne la recompilez pas.** `pecl install grpc` prend environ sept
minutes, et votre construction d'image les paie sur chaque branche. Des extensions préconstruites
sont publiées pour PHP 8.2 à 8.5, en versions thread-safe et non thread-safe — voir
[gRPC dans votre image de conteneur](../container-images/) pour les recettes
`COPY --from`, php-fpm, mod_php et FrankenPHP compris.

### Mise en place Docker Compose (local / intégration continue)

Le dépôt fournit un `compose.yaml` prêt à l'emploi sous `symfony/`, qui démarre :
- **PostgreSQL 16** (partagé entre l'application et Temporal) ;
- **`temporalio/auto-setup:1.25.2`** (configure le schéma au démarrage) ;
- l'**interface Temporal** (sur le port 8088).

```bash
cd symfony
docker compose up -d
```

Attendez que la pile soit saine, puis démarrez les workers Symfony :

```bash
php bin/console messenger:consume durable_temporal_journal --time-limit=3600
php bin/console messenger:consume durable_temporal_activity --time-limit=3600
```

Le binaire `symfony serve` lit `.symfony.local.yaml` et démarre les workers tout seul s'ils y sont
configurés.

### Configuration

```yaml
# .env.local (dev/prod)
DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
MESSENGER_DURABLE_WORKFLOW_DSN=in-memory://
MESSENGER_DURABLE_ACTIVITY_DSN=in-memory://
```

```yaml
# config/packages/durable.yaml
durable:
    event_store:
        type: in_memory   # Temporal est la vraie source de l'historique ; l'en-mémoire sert de cache local en écriture traversante
    temporal:
        dsn: '%env(DURABLE_DSN)%'

# config/packages/messenger.yaml
when@dev:
    framework:
        messenger:
            transports:
                durable_temporal_journal:
                    dsn: '%env(DURABLE_DSN)%'
                durable_temporal_activity:
                    dsn: '%env(DURABLE_DSN)%'
                    options:
                        purpose: activity_worker
            routing:
                Gplanchat\Durable\Transport\FireWorkflowTimersMessage: durable_workflows
```

### L'interface Temporal

Avec la configuration Docker par défaut, l'**interface web de Temporal** est disponible sur
[http://localhost:8088](http://localhost:8088). Elle montre les workflows en cours et terminés, leur
historique, et les activités en échec.

### Les paramètres du DSN

| Paramètre | Requis | Exemple | Description |
|-----------|--------|---------|-------------|
| `namespace` | oui | `default` | Espace de noms Temporal. Prenez des espaces distincts par application et par environnement. |
| `journal_task_queue` | oui | `durable-journal` | File de tâches du worker de tâches de workflow. |
| `activity_task_queue` | oui | `durable-activities` | File de tâches du worker d'activités. |
| `tls` | non (défaut `0`) | `tls=1` | Active TLS pour gRPC. Requis pour Temporal Cloud. |

### Temporal Cloud

Pour **Temporal Cloud**, activez TLS et pointez le point d'entrée Cloud :

```
DURABLE_DSN=temporal://ACCOUNT.REGION.tmprl.cloud:7233?namespace=NAMESPACE.ACCOUNT&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=1
```

Les certificats TLS se montent et se configurent par les identifiants de canal gRPC (voir les points
d'extension dans les sources du pont).

---

## Le backend DBAL

Le backend DBAL persiste le journal, les métadonnées de reprise et les liens parent/enfant dans une
**seule base SQL**, à travers Doctrine DBAL. Pas de serveur d'orchestration, pas de sidecar, pas
d'`ext-grpc`. Voir **DUR030**.

### Comment il fonctionne

- Les trois stockages locaux au processus deviennent des tables SQL ; tout le reste — rejeu, tampon
  de commandes, cycle de vie — est le code que le backend en mémoire fait déjà tourner.
- Reprises et activités voyagent par **Symfony Messenger** : prenez donc un transport durable
  (Doctrine, Redis, AMQP). Un transport `in-memory://` jette ce que le journal SQL vient de
  persister.
- Les minuteurs voyagent par le `DelayStamp` de Messenger, via `FireWorkflowTimersHandler`.
- Les tables sont créées à la **première écriture** — aucune migration à jouer, aucune dépendance à
  `doctrine/migrations`.

### Configuration

```yaml
# config/packages/durable.yaml
durable:
    dbal:
        connection: doctrine.dbal.default_connection
        lock_factory: lock.factory
    event_store:
        type: dbal
    workflow_metadata:
        type: dbal
    child_workflow:
        parent_link_store:
            type: dbal
    activity_transport:
        type: messenger
        transport_name: durable_activities

framework:
    lock:
        default: '%env(LOCK_DSN)%'   # doctrine://default, redis://… — doit être partagé entre les workers
```

Poser `event_store.type: dbal` en même temps qu'un `temporal.dsn` non vide lève à la compilation :
le journal ne peut pas avoir deux sources de vérité.

### Une reprise à la fois — la chose à ne pas rater

Temporal sérialise les tâches de workflow d'une exécution côté serveur. Ici il n'y a pas de serveur :
deux consommateurs peuvent donc défiler deux reprises de la même exécution et rejouer la même fibre
en parallèle, chacun ajoutant ses propres commandes — **activités dupliquées, journal bifurqué**.

Durable l'empêche par un verrou par exécution (`SingleResumeLockMiddleware`), enregistré
automatiquement quand le stockage d'événements DBAL est actif. **Il ne vaut que ce que vaut votre
magasin de verrous** : un `lock.factory` en mémoire ou local au processus, avec plusieurs workers,
vous redonne exactement la panne que le verrou existe pour empêcher. Configurez-en un partagé.

### Quand l'employer

- **En production, sans opérer de cluster** — une application Symfony qui a déjà une base de données
  et un transport Messenger.
- Pour des workflows longs qui doivent survivre aux déploiements et aux redémarrages, à une échelle
  qu'une seule base peut tenir.

Pas pour : les requêtes par attributs de recherche, les planifications cron, ni le débit et la
visibilité qu'apporte un cluster Temporal. Voir la matrice de capacités plus bas.

### Le même échange, sur la connexion de Laravel

Les mêmes quatre stockages existent sur `Illuminate\Database\Connection`, sous le nom
[`gplanchat/durable-bridge-illuminate`](../packages/#gplanchatdurable-bridge-illuminate--le-backend-laravel)
— même journal, même échange face à Temporal.

Ce n'est **pas une quatrième valeur d'`event_store.type`**, et ça ne le sera jamais : une
application Laravel ne lit pas le YAML de cette page. Le pont est la moitié stockage, et **ce qui le
lie, c'est `gplanchat/durable-laravel`**, par son propre `config/durable.php` publié.

Ce paquet porte aussi le côté file — activités et reprises en jobs, un minuteur comme reprise
différée sur le délai natif de la file, et l'exclusion par exécution que décrit la section
ci-dessus. Son [entrée dans la page Paquets](../packages/#gplanchatdurable-laravel--lintégration-laravel)
donne la configuration, les trois réglages qu'il refuse plutôt que de les tolérer, et les deux
comportements qui ressemblent à des bugs sans en être.

**L'échange face à Temporal est celui du pont DBAL, mot pour mot.** Ce qui change est la connexion,
et pourquoi : un stockage sur `DB::connection()` est dans `DB::transaction()` par construction, ce
qu'exige DUR030. Voir [DUR047](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR047-laravel-the-host-that-measured-before-it-wired.md).

---

## Choisir un backend par environnement

```
┌───────────────────────────┬───────────────────────────────────────────────────┐
│ Environnement             │ Backend                                           │
├───────────────────────────┼───────────────────────────────────────────────────┤
│ Tests unitaires           │ En mémoire (DurableTestCase)                      │
│ Tests d'intégration       │ En mémoire (DurableBundleTestTrait + KernelTestCase)│
│ Intégration continue avec │ Temporal (groupe temporal-integration)            │
│   Temporal                │                                                   │
│ Développement local       │ Au choix (en mémoire pour la vitesse, DBAL/Temporal pour le réalisme)│
│ Production, sans cluster  │ DBAL                                              │
│ Production, à l'échelle   │ Temporal                                          │
└───────────────────────────┴───────────────────────────────────────────────────┘
```

---

## Matrice de capacités {#capability-matrix}

Les trois backends font tourner le **même pilote à fibres** et le même chemin d'exécution des
activités. Ce qui diffère, c'est ce que la plateforme autour sait offrir.

| Capacité | En mémoire | DBAL | Temporal |
|---|---|---|---|
| Activités, réessais, délais | ✅ | ✅ | ✅ |
| Minuteurs, effets de bord | ✅ | ✅ (délais Messenger) | ✅ |
| Signaux, mises à jour, requêtes | ✅ | ✅ | ✅ |
| Workflows enfants | ✅ | ✅ | ✅ |
| Cascade `ParentClosePolicy` | ✅ | ✅ | ✅ (pilotée par le serveur) |
| Continue-as-new | ✅ | ✅ | ✅ |
| Annulation avec compensation | ✅ | ✅ | ✅ |
| Survit au redémarrage du processus | ❌ | ✅ | ✅ |
| Sérialisation des tâches par exécution | sans objet (processus unique) | verrou applicatif | ✅ côté serveur |
| Attributs de recherche | journalisés seulement | journalisés seulement | ✅ indexés et interrogeables |
| Planifications cron | ❌ pas d'ordonnanceur | ❌ pas d'ordonnanceur | ✅ |
| Rétention d'historique / API de visibilité | ❌ | votre table SQL | ✅ |
| Opérations Nexus (appeler **et** servir) | ❌ | ❌ | ✅ |

Ni le backend en mémoire ni le backend DBAL n'ont d'ordonnanceur ou de frontière entre espaces de
noms : cron et Nexus n'y ont donc pas d'équivalent. Là où une capacité manque, elle **échoue
explicitement** plutôt que d'être ignorée en silence — pour un *appel* Nexus, à l'appel ; pour un
*gestionnaire* Nexus, au montage du conteneur, puisqu'un gestionnaire sans route n'est pas un appel
qui échoue mais un service qui ne reçoit jamais rien.

---

## Les réessais ont la même sémantique partout

Une activité sans borne de tentatives réessaie **indéfiniment** sur les deux backends — c'est le
défaut de Temporal. Le `max_activity_retries` du bundle agit toujours comme un plafond quand une
activité n'en pose pas ; à `0`, il ne plafonne rien.

Voir [Échecs et réessais](../failures/) et [Options](../options/#retrylimit).

---

## Écrire son propre backend

Deux ports définissent un backend : `WorkflowCommandBufferInterface` pour ce qu'un workflow demande,
et `WorkflowHistorySourceInterface` pour ce qui s'est déjà passé.

Les deux portent des **objets valeur**, pas des primitives. Une implémentation reçoit les options
telles que l'appelant les a construites — limites de réessai, délais, files de tâches, planifications
cron — et lui appartient la traduction vers sa propre représentation, sérialisation et lecture
d'horloge comprises.

`startTimer()` reçoit un **délai**, pas une échéance : en faire un instant est votre décision, avec
votre horloge. C'est ce qui permet à un harnais de test d'avancer une horloge virtuelle, et au
pilote Temporal de passer la durée que le serveur attend.

La décision de contribution est [DUR031](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR031-value-objects-across-ports-and-wire-ownership.md).

---

## Voir aussi

- [Référence de configuration](../configuration/) — la liste complète des clés de `durable.yaml`.
- [Premiers pas](../getting-started/) — routage Messenger et commandes du worker.
- [Tester des workflows](../testing/) — se servir du backend en mémoire dans les tests.
