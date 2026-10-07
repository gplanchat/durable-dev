---
title: Backends
weight: 15
---

# Backends

Durable propose quatre backends d'exécution. Un backend est l'endroit où vit le journal et ce qui
planifie le travail ; le journal est la suite d'événements, en ajout seul, qui enregistre tout ce
qu'une exécution a décidé et reçu (voir le [glossaire](../glossary/)). L'un est le backend en
mémoire ; les trois autres sont les ponts entre lesquels vous choisissez.

| Backend | Usage |
|---------|-------|
| **En mémoire** | Tests unitaires, tests fonctionnels, exploration locale ; aucun serveur nécessaire. |
| **DBAL** | Production sans cluster d'orchestration : une base SQL, pas d'`ext-grpc`. |
| **Illuminate** | Le même, sur la connexion de Laravel plutôt que sur celle de Doctrine. |
| **Temporal** | Production et recette à l'échelle, tests d'intégration réalistes ; `ext-grpc` et un cluster Temporal requis. |

> [!NOTE]
> **Sur Magento, les deux backends SQL ne sont pas disponibles.** `gplanchat/durable-magento`
> déclare un `conflict` Composer sur les deux ponts SQL : `Magento\Framework\App\ResourceConnection`
> n'est ni une connexion Doctrine DBAL ni celle d'Illuminate, donc aucun des deux n'a de quoi se
> lier. L'état vit soit dans un cluster Temporal, soit dans un processus. La présence de
> `durable/temporal/dsn` dans `app/etc/env.php` détermine lequel des deux ; aucun réglage ne le fait.

Les quatre font tourner le **même pilote à fibres** et le même code de workflows et d'activités.
Vous en choisissez trois par `durable.backend` (et `DURABLE_DSN` pour Temporal). **Illuminate
n'en est pas une valeur** et ne le sera jamais ; [le backend Illuminate](#illuminate-backend)
décrit ce qui le lie à la place.

---

## Le backend en mémoire

Le backend en mémoire tourne entièrement dans un seul processus PHP. Il ne demande ni serveur
externe ni gRPC, et rien ne persiste d'une requête à l'autre.

### Comment il fonctionne

- Les messages de workflow et d'activité passent par des transports **Symfony Messenger** en mémoire.
- L'historique d'événements vit dans un `InMemoryEventStore`.
- La vidange Messenger traite les messages de façon synchrone quand vous appelez
  `drainMessengerUntilSettled()` ou son équivalent.

### Configuration

```yaml
# config/packages/durable.yaml (ou when@test:)
durable:
    backend: in_memory
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
3. Le worker `durable_workflows` (un processus qui tire le travail ; voir le
   [glossaire](../glossary/)) récupère les **tâches de workflow**.
4. Le worker `durable_activities` récupère les **tâches d'activité**.
5. Chaque tâche de workflow rejoue l'historique avec le `WorkflowTaskRunner` à fibres et renvoie
   ses commandes à Temporal. Le rejeu exécute à nouveau le code du workflow depuis sa première
   ligne et sert chaque étape enregistrée depuis le journal.

Le bundle enregistre lui-même ces workers à partir de `durable.temporal.dsn` : `messenger:consume`
les trouve par leur nom, et `messenger.yaml` ne déclare aucun transport Temporal. Un troisième,
`durable_nexus`, existe quand l'application [sert une opération Nexus](../nexus/).

### Attendre le résultat avec `pollForCompletion()` {#waiting-for-the-result}

`WorkflowClient::pollForCompletion()` lit l'événement de clôture de l'exécution jusqu'à ce qu'il
arrive, puis renvoie le résultat ou lève une exception. Deux comportements diffèrent d'une exécution
sur les backends à journal :

- Un workflow qui laisse échapper l'échec d'une activité lève `DurableWorkflowAlgorithmFailureException`,
  comme sur les backends à journal. Son exception précédente est une `ActivityFailureCauseException`
  qui porte la classe et le message d'origine, pas l'exception d'origine elle-même. Tout autre échec
  du workflow lève une simple `\RuntimeException` dont le message commence par
  `Workflow "<execution id>" failed:`, et non l'exception propre au workflow.
- Un workflow qui attend un signal que personne n'envoie échoue aussitôt en mémoire, puisque rien
  d'autre ne peut le faire avancer. Sur Temporal, l'exécution reste ouverte : `pollForCompletion()`
  attend jusqu'au bout de ses interrogations, puis lève `WorkflowStuckException`.

### Prérequis

- L'extension PHP **`ext-grpc`**, compilée contre la version du paquet `grpc/grpc` qu'exige le pont.
- Un cluster Temporal en marche, **serveur 1.20 ou plus récent**. La 1.20 est la plus ancienne
  version que Durable prend en charge : c'est la première dont la visibilité SQL (PostgreSQL, MySQL,
  SQLite) accepte des attributs de recherche personnalisés. Sur PostgreSQL, il faut pour cela le
  greffon de persistance `postgres12` (`DB=postgres12` avec l'image `auto-setup`). L'ancien
  greffon `postgresql` n'offre que la visibilité standard, qui ne peut pas filtrer sur des
  attributs personnalisés : les filtres de la liste des exécutions y échouent. L'intégration
  continue exécute les suites de la liste des exécutions et des requêtes de visibilité contre
  `temporalio/auto-setup:1.20` sur PostgreSQL, en plus d'un serveur récent.
- Le filtre de la liste des exécutions par **préfixe d'identifiant d'exécution** demande un
  **serveur 1.23 ou plus récent** : les serveurs plus anciens, 1.22 compris, ne peuvent pas
  exécuter le `STARTS_WITH` qu'il demande. Sur ces serveurs, le catalogue lève pour un préfixe une
  `RunFilterUnavailableException` qui nomme la 1.23, et les tableaux de bord ne proposent que le
  filtre par nom. Le filtre exact par nom de workflow fonctionne dès la 1.20.
- Les **mises à jour** (`#[AsUpdateMethod]`, `onUpdate()`) demandent un **serveur 1.21 ou plus
  récent**. Sur la 1.20, quand la tâche de workflow qui répond à une mise à jour termine aussi le
  workflow, le serveur n'écrit aucun événement de mise à jour dans l'historique, et un rejeu
  ultérieur ne voit pas la mise à jour.
  De la 1.21 à la 1.24, les mises à jour sont désactivées par défaut : passez la valeur de
  configuration dynamique `frontend.enableUpdateWorkflowExecution` à `true`. Sans elle,
  `WorkflowClient::update()` échoue avec `UpdateWorkflowExecution operation is disabled on this
  namespace`. Dans le fichier de configuration dynamique du serveur (inutile d'activer
  `frontend.enableUpdateWorkflowExecutionAsyncAccepted` : Durable attend l'étape COMPLETED de la
  mise à jour) :

  ```yaml
  frontend.enableUpdateWorkflowExecution:
    - value: true
  ```

### Installer `ext-grpc`

```bash
pecl install grpc
# À ajouter dans php.ini : extension=grpc
```

Vérification :

```bash
php -m | grep grpc
```

**Dans une image de conteneur, copiez l'extension au lieu de la compiler.** `pecl install grpc` prend environ sept
minutes, et votre construction d'image les paie sur chaque branche. Des extensions préconstruites
sont publiées pour PHP 8.2 à 8.5, en versions thread-safe et non thread-safe ; voir
[gRPC dans votre image de conteneur](../container-images/) pour les recettes
`COPY --from`, php-fpm, mod_php et FrankenPHP compris.

### Filtrer les exécutions par nom de workflow et par identifiant {#register-durables-search-attributes}

Avec `search_attributes` activé, Durable écrit deux attributs de recherche sur chaque exécution
qu'il démarre, pour que la liste des exécutions puisse filtrer par nom de workflow et par
identifiant d'exécution. L'option est désactivée par défaut. Un démarrage qui renseigne un
attribut inconnu de l'espace de noms échoue sur le serveur. Enregistrez donc les deux attributs
**une fois par espace de noms, avant d'activer l'option** :

```bash
temporal operator search-attribute create --namespace default \
    --name DurableWorkflowName --type Keyword \
    --name DurableExecutionId --type Keyword
```

- Vous pouvez relancer la commande : elle réussit tant que le type ne change pas.
- **Attendez quelques secondes avant le premier démarrage.** Les attributs apparaissent tout de
  suite dans `search-attribute list`, mais pendant deux ou trois secondes un démarrage qui les
  renseigne échoue encore avec `Namespace default has no mapping defined for search attribute
  DurableExecutionId`. Un script peut attendre que cette requête cesse d'échouer :

  ```bash
  until temporal workflow list --namespace default --limit 1 \
      --query "DurableExecutionId = 'probe'" >/dev/null 2>&1; do sleep 1; done
  ```

- Sur **Temporal Cloud**, ajoutez les deux attributs à l'espace de noms depuis l'interface Cloud ou
  avec `tcld namespace search-attributes add`.
- Avec une visibilité SQL (PostgreSQL, MySQL, SQLite), les attributs personnalisés demandent un
  serveur 1.20 ou plus récent. Un espace de noms y compte au plus 10 attributs Keyword, et Durable
  en prend deux.

Activez ensuite l'option :

```yaml
# config/packages/durable.yaml
durable:
    temporal:
        search_attributes: true
```

Sous Laravel, mettez `'search_attributes' => true` sous `temporal` dans `config/durable.php`. Sous
Magento, mettez `durable/temporal/search_attributes` à `true` dans `app/etc/env.php`.

**Comment les valeurs sont écrites.** Une barre oblique inverse devient un point, si bien qu'un nom
de classe se lit `App.Workflow.OrderWorkflow` : l'analyseur de requêtes de Temporal ne trouve
aucune valeur qui en contient une. Un `.` ou un `%` déjà présent devient `%2E` ou `%25`, si bien
que deux noms de workflow n'ont jamais la même valeur. Temporal documente une limite de
255 caractères par valeur. Une valeur plus longue garde au plus ses 189 premiers octets et se termine par
une empreinte de l'ensemble : un filtre exact la retrouve toujours, mais un filtre par préfixe ne
fonctionne que dans ces premiers octets.

**Les exécutions démarrées avant l'activation de l'option ne portent pas ces attributs.** Elles
restent dans la liste sans filtre, mais un filtre par nom de workflow ou par identifiant
d'exécution ne les trouve pas.

### Mise en place Docker Compose (local / intégration continue)

Le dépôt fournit un `compose.yaml` prêt à l'emploi sous `symfony/`, qui démarre :
- **PostgreSQL 16** (partagé entre l'application et Temporal) ;
- **`temporalio/auto-setup:1.25.2`** (configure le schéma au démarrage) ;
- l'**interface Temporal** (sur le port 8088).

```bash
cd symfony
docker compose up -d
```

Le service `temporal` enregistre lui-même [les attributs de recherche de
Durable](#register-durables-search-attributes), et ne se déclare sain qu'une fois qu'un démarrage
peut s'en servir ; le banc active l'option. Attendez que la pile soit saine, puis démarrez les workers Symfony :

```bash
php bin/console messenger:consume durable_workflows --time-limit=3600
php bin/console messenger:consume durable_activities --time-limit=3600
```

Le binaire `symfony serve` lit `.symfony.local.yaml` et démarre les workers tout seul s'ils y sont
configurés.

### Configuration

```yaml
# .env.local (dev/prod)
DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
```

```yaml
# config/packages/durable.yaml
durable:
    backend: temporal
    temporal:
        dsn: '%env(DURABLE_DSN)%'
```

Rien ne va dans `messenger.yaml` pour Temporal. S'il déclare encore `durable_workflows` ou
`durable_activities` avec ce backend, la compilation du conteneur échoue, et l'erreur nomme le
transport à retirer, car ces noms appartiennent aux workers du bundle. L'exception est
`durable.backend: dbal` avec un DSN : les workflows tournent alors en local, et ces deux transports
restent ceux de l'application.

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

Avec une clé d'API, ajoutez `api_key=` (encodée pour l'URL) ; en mTLS, `cert=` et `key=` (chemins de
fichiers PEM) ; avec une autorité privée, `ca=`. La liste complète est dans [les paramètres du
DSN](../configuration/#format-du-dsn).

---

## Le backend DBAL

Le backend DBAL persiste le journal, les métadonnées de reprise, les liens parent/enfant et le
catalogue des exécutions (la liste des exécutions que lit un tableau de bord) dans une **seule base
SQL**, à travers Doctrine DBAL. Pas de serveur d'orchestration, pas de sidecar, pas d'`ext-grpc`.
Voir **DUR030**.

### Comment il fonctionne

- Les quatre stockages locaux au processus deviennent des tables SQL : le journal d'événements,
  les métadonnées de workflow, les liens parents des workflows enfants et le catalogue des
  exécutions. Une cinquième table, `durable_execution_heads`, tient un compteur par exécution qui
  empêche une reprise dépassée d'écrire dans le journal (DUR053). Tout le reste (rejeu, tampon de commandes, cycle de vie) est le code que le backend
  en mémoire fait déjà tourner.
- Reprises et activités voyagent par **Symfony Messenger** : prenez donc un transport durable
  (Doctrine, Redis, AMQP). Un transport `in-memory://` jette ce que le journal SQL vient de
  persister.
- Les minuteurs voyagent par le `DelayStamp` de Messenger, via `FireWorkflowTimersHandler`.
- Les tables sont créées à la **première écriture** : aucune migration à jouer, aucune dépendance à
  `doctrine/migrations`. `bin/console durable:setup` les crée d'avance ; lancez cette commande quand
  `dbal.auto_setup` vaut `false`, ou quand la première écriture a lieu dans une transaction : la
  création automatique y échoue, quelle que soit la base (sur MySQL, le `CREATE TABLE` validerait
  cette transaction).

### Configuration

Donnez au journal une connexion à lui. Partager celle de l'application est fortement déconseillé
(DUR054) : les transactions de Durable s'imbriquent alors dans les transactions métier. Un worker
qui démarre avec le journal sur la connexion par défaut de l'application le signale par un
avertissement dans les logs. Mieux encore : faites pointer cette connexion vers une base (ou un
schéma) et un utilisateur SQL propres à Durable, pour que le code métier ne puisse pas du tout
atteindre les tables du journal.

```yaml
# config/packages/doctrine.yaml : le journal sur une connexion à lui
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            durable:
                url: '%env(resolve:DURABLE_DATABASE_URL)%'
```

```yaml
# config/packages/durable.yaml
durable:
    dbal:
        connection: doctrine.dbal.durable_connection
        lock_factory: lock.factory
    backend: dbal
    activity_transport:
        type: messenger
        transport_name: durable_activities

framework:
    lock:
        default: '%env(LOCK_DSN)%'   # une URL DBAL (postgresql://…, mysql://…), redis://… ; pas le doctrine:// de Messenger ; partagé entre les workers
```

DoctrineBundle nomme le service de chaque connexion `doctrine.dbal.<nom>_connection`. Sur une base
autre que celle de l'ORM, `doctrine:migrations:diff` ne voit pas les tables de Durable : elles
viennent de la première écriture, ou de `bin/console durable:setup`.

Ajouter un `temporal.dsn` garde le journal en SQL et n'utilise le cluster que pour servir des
opérations Nexus. Avec `backend: temporal`, c'est le cluster qui porte le journal. Dans les deux
cas, le journal vit à un seul endroit.

### Le transport Doctrine sur PostgreSQL {#doctrine-transport-on-postgresql}

Sur PostgreSQL, réglez `use_notify: false` sur les transports Doctrine de Durable :

```yaml
framework:
    messenger:
        transports:
            durable_workflows:
                dsn: 'doctrine://default?queue_name=durable_workflows'
                options: { use_notify: false }
            durable_activities:
                dsn: 'doctrine://default?queue_name=durable_activities'
                options: { use_notify: false }
```

Une fois une file vide, le transport PostgreSQL de Messenger ne la relit qu'à réception d'une
notification ou au bout de 60 secondes (`check_delayed_interval`). Un worker qui consomme les deux
files sur une seule connexion peut manquer cette notification, et la reprise qu'envoie une activité
attend alors jusqu'à 60 secondes, ou le prochain lancement de `durable:worker`, quelle que soit la
valeur de `--sleep`. Avec `use_notify: false`, le transport interroge chaque file à chaque tour, comme sur
MySQL.

### Une seule reprise à la fois par exécution {#une-reprise-à-la-fois--la-chose-à-ne-pas-rater}

Temporal sérialise les tâches de workflow d'une exécution côté serveur. Ici il n'y a pas de serveur :
deux consommateurs peuvent donc défiler deux reprises de la même exécution et rejouer la même fibre
en parallèle, chacun ajoutant ses propres commandes. Il en résulte des **activités dupliquées et
un journal bifurqué**.

Durable l'empêche par un verrou par exécution (`SingleResumeLockMiddleware`), enregistré
automatiquement quand le stockage d'événements DBAL est actif. **Le verrou ne vaut que ce que vaut
votre magasin de verrous.** Avec plusieurs workers, un `lock.factory` en mémoire ou local au
processus laisse passer exactement la panne que le verrou existe pour empêcher. Configurez-en un
partagé.

### Quand l'employer

- **En production, sans opérer de cluster.** Une application Symfony qui a déjà une base de données
  et un transport Messenger.
- Pour des workflows longs qui doivent survivre aux déploiements et aux redémarrages, à une échelle
  qu'une seule base peut tenir.

Il n'offre ni les requêtes par attributs de recherche, ni les planifications cron, ni le débit et
la visibilité d'un cluster Temporal. La matrice de capacités plus bas liste les différences.

---

## Le backend Illuminate {#illuminate-backend}

Les mêmes quatre stockages existent sur `Illuminate\Database\Connection`, sous le nom
[`gplanchat/durable-bridge-illuminate`](../packages/#gplanchatdurable-bridge-illuminate--le-backend-laravel)
avec le même journal et le même compromis face à Temporal.

**Son compromis face à Temporal est celui du pont DBAL, mot pour mot.** Seule la connexion diffère :
`Illuminate\Database\Connection` au lieu de celle de Doctrine. Donnez-lui une connexion à elle,
distincte de celle de l'application (DUR054). Voir [DUR047](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR047-laravel-the-host-that-measured-before-it-wired.md).

### Le lier par `gplanchat/durable-laravel` {#ce-qui-le-lie-nest-pas-le-yaml-de-cette-page}

Illuminate n'est **pas une quatrième valeur de `backend`**, et ne le sera jamais, car une
application Laravel ne lit pas le YAML de cette page. Le pont est la moitié stockage.
**`gplanchat/durable-laravel` le lie**, par son propre `config/durable.php` publié.

Ce paquet porte aussi le côté file : activités et reprises en jobs, un minuteur comme job de
déclenchement différé sur le délai natif de la file, et l'exclusion par exécution que décrit la
section DBAL. Son
[entrée dans la page Paquets](../packages/#gplanchatdurable-laravel--lintégration-laravel)
donne la configuration, les trois réglages que le paquet n'accepte pas, et les deux comportements
qui ressemblent à des bugs sans en être.

---

## Choisir un backend par environnement

| Environnement | Backend |
|---|---|
| Tests unitaires | En mémoire (`DurableTestCase`) |
| Tests d'intégration | En mémoire (`DurableBundleTestTrait` + `KernelTestCase`) |
| Intégration continue avec Temporal | Temporal (groupe `temporal-integration`) |
| Développement local | Au choix : en mémoire pour la vitesse, un backend à journal pour le réalisme |
| Production, sans cluster | DBAL sous Symfony, Illuminate sous Laravel |
| Production, à l'échelle | Temporal |

---

## Matrice de capacités {#capability-matrix}

Les quatre backends font tourner le **même pilote à fibres** et le même chemin d'exécution des
activités. Ce qui diffère, c'est ce que la plateforme autour peut offrir. Les deux colonnes SQL ne
diffèrent que par la connexion sur laquelle elles reposent : leurs réponses concordent sur chaque
ligne, sauf pour le transport et ce que l'hôte livre (signaux, mises à jour et service Nexus).

La colonne Magento Database n'est pas un backend de la version courante. Son code est en cours de
développement (épopée [#740](https://github.com/gplanchat/durable-dev/issues/740)), et rien n'en
est sur `main`. Une cellule indique « pas encore » tant que la capacité n'est pas fusionnée.

| Capacité | En mémoire | DBAL | Illuminate | Temporal | Magento Database |
|---|---|---|---|---|---|
| Activités, réessais, délais | ✅ | ✅ | ✅ | ✅ | pas encore |
| Minuteurs, effets de bord | ✅ | ✅ (délais Messenger) | ✅ (délais de la file) | ✅ | pas encore |
| Gestionnaires de signaux, de mises à jour et de requêtes dans un workflow | ✅ | ✅ | ✅ | ✅ | pas encore |
| Envoi d'un signal ou d'une mise à jour depuis l'application | ✅ (message Symfony) | ✅ (message Symfony) | ❌ (Laravel n'en livre aucun) | ✅ (client ou message Symfony) | pas encore |
| Résultat de la mise à jour renvoyé à l'appelant | ❌ | ❌ | ❌ | ✅ (`WorkflowClient::update()`) | pas encore |
| Lecture d'une requête depuis l'application | ❌ | ❌ | ❌ | ✅ (`WorkflowClient::query()`) | pas encore |
| Workflows enfants | ✅ | ✅ | ✅ | ✅ | pas encore |
| Cascade `ParentClosePolicy` | ✅ | ✅ | ✅ | ✅ (pilotée par le serveur) | pas encore |
| Continue-as-new | ✅ | ✅ | ✅ | ✅ | pas encore |
| Annulation avec compensation (le `RequestCancel` d'un parent) | ✅ | ✅ | ✅ | ✅ | pas encore |
| Annulation demandée de l'extérieur | ❌ | ❌ | ❌ | ✅ | pas encore |
| Survit au redémarrage du processus | ❌ | ✅ | ✅ | ✅ | pas encore |
| Sérialisation des tâches par exécution | sans objet (processus unique) | verrou applicatif | verrou applicatif | ✅ côté serveur | pas encore |
| Attributs de recherche | journalisés seulement | journalisés seulement | journalisés seulement | ✅ indexés et interrogeables | pas encore |
| Planifications cron | ❌ pas d'ordonnanceur | ❌ pas d'ordonnanceur | ❌ pas d'ordonnanceur | ✅ | pas encore |
| Rétention d'historique / API de visibilité | ❌ | votre table SQL | votre table SQL | ✅ | pas encore |
| Appel d'une opération Nexus | ❌ | ❌ | ❌ | ✅ | pas encore |
| Service d'une opération Nexus | ✅ avec `temporal.dsn` (Symfony) | ✅ avec `temporal.dsn` (Symfony) | ❌ | ✅ (Symfony, Laravel, Magento) | pas encore |

`gplanchat/durable-magento` ne livre ni signal ni mise à jour. Sur les backends à journal, un signal
ou une mise à jour envoyé depuis l'application est écrit au journal, et la passe suivante du
workflow le traite ; l'émetteur ne reçoit aucune réponse. Une requête n'y a aucun point d'entrée
côté application.

Aucun backend hors Temporal n'a d'ordonnanceur ou de frontière entre espaces de noms : cron et Nexus
n'ont donc pas d'équivalent sur les trois autres. Nexus échoue explicitement, à une lacune près sur
Laravel, décrite plus bas. Le `namespace`, le `taskQueue` et le `cronSchedule` d'un workflow enfant
échouent aussi explicitement : un backend à journal échoue avec `UnsupportedByBackendException` en
nommant l'option. Les attributs de recherche font exception : ceux d'un workflow enfant sont écrits
au journal et rien ne les lit hors de Temporal, et les options de démarrage d'un workflow racine
n'existent que sur le client Temporal. Un *appel* Nexus échoue à l'appel. Un *gestionnaire* Nexus
sans route ne voit jamais d'appel échouer : c'est un service qui ne reçoit jamais rien. Sur Symfony,
le montage du conteneur échoue quand `durable.temporal.dsn` n'est pas renseigné. Sur Magento,
`bin/magento durable:worker --role=nexus` échoue avec `A Nexus worker needs a cluster` quand
`app/etc/env.php` n'a pas de DSN.
Sur Laravel, rien n'échoue au démarrage. Hors de `temporal`, rien ne résout le registre Nexus : un
gestionnaire listé dans `durable.nexus.handlers` ne lève rien et ne reçoit rien, et
`php artisan durable:nexus-worker` se termine sur `Command "durable:nexus-worker" is not defined.`,
qui ne nomme pas le backend (voir [#931](https://github.com/gplanchat/durable-dev/issues/931)).

### Démarrer une exécution depuis un observateur Magento {#magento-start-blocks}

`RuntimeFactory::resumeDispatcher()->dispatchNewWorkflowRun()` a la même signature et le même
comportement en cas d'échec sur les backends mémoire et Temporal de Magento : un workflow qui échoue ne lève pas
d'exception depuis l'appel, un workflow non déclaré en lève une. Une différence subsiste, nommée ici
comme exception à la règle selon laquelle l'application se comporte de la même façon sur tous les
backends. Sur Temporal, l'appel démarre l'exécution et rend la main. Sur le backend mémoire de
Magento, l'exécution s'effectue dans le processus appelant : la requête l'attend, pendant
`budgetSeconds` au plus (10 par défaut), et un workflow qui attend un signal ou un long minuteur
retient la requête pendant tout le budget. Rien d'autre ne peut faire avancer une exécution en
mémoire, donc l'attente ne peut pas disparaître. Renseignez `durable/temporal/dsn` là où une requête
ne doit pas attendre.

Une seconde différence concerne l'échec que l'appel absorbe. Le journal en mémoire s'arrête avec la
requête : la ligne de log est donc la seule trace de l'échec, et seulement si un logger est
configuré. Sur Temporal, l'échec reste aussi dans l'historique du cluster.

---

## Les limites de réessais diffèrent sur un réglage {#les-réessais-ont-la-même-sémantique-partout}

Une activité sans borne de tentatives réessaie **indéfiniment** sur tous les backends, c'est le
défaut de Temporal.

`max_activity_retries` fait exception. Sur le backend en mémoire et sur les backends à journal
(DBAL, Illuminate), le worker resserre la `RetryLimit` de l'activité à ce plafond, et la plus
stricte des deux s'applique ; à `0`, il ne plafonne rien. Sous Temporal, le cluster relance d'après
la `RetryLimit` propre à l'activité et ne lit pas ce réglage : une activité que le plafond
arrête sur les autres backends continue de réessayer sous Temporal.

Voir [Échecs et réessais](../failures/) et [Options](../options/#retrylimit).

---

## Écrire son propre backend

Deux ports définissent un backend : `WorkflowCommandBufferInterface` pour ce qu'un workflow demande,
et `WorkflowHistorySourceInterface` pour ce qui s'est déjà passé.

Les deux portent des **objets valeur**, pas des primitives. Une implémentation reçoit les options
telles que l'appelant les a construites (limites de réessai, délais, files de tâches, planifications
cron), et lui appartient la traduction vers sa propre représentation, sérialisation et lecture
d'horloge comprises.

`startTimer()` reçoit un **délai**, pas une échéance. C'est vous qui en faites un instant, avec
votre propre horloge. Cela permet à un harnais de test d'avancer une horloge virtuelle, et au
pilote Temporal de passer la durée que le serveur attend.

La décision de contribution est [DUR031](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR031-value-objects-across-ports-and-wire-ownership.md).

---

## Voir aussi

- [Référence de configuration](../configuration/) liste toutes les clés de `durable.yaml`.
- [Premiers pas](../getting-started/) couvre le routage Messenger et les commandes du worker.
- [Tester des workflows](../testing/) montre le backend en mémoire à l'œuvre dans les tests.
