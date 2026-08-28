---
title: Paquets
weight: 5
---

# Paquets

Durable, c'est un cœur, une intégration de framework facultative, et un choix de backend. Vous
prenez ce dont vous avez besoin : la bibliothèque seule suffit à écrire et à tester unitairement un
workflow, et rien de ce qui se pose au-dessus ne change le code du workflow — seulement l'endroit où
l'exécution est enregistrée.

| Paquet | Apporte | Exige |
|---|---|---|
| `gplanchat/durable` | workflows, activités, minuteurs, journal d'événements, backend en mémoire | `psr/cache` |
| `gplanchat/durable-bundle` | câblage Symfony, commandes de worker, panneau du profileur | la bibliothèque, framework-bundle et Messenger |
| `gplanchat/durable-bridge-temporal` | le pilote Temporal, en gRPC | la bibliothèque, `ext-grpc`, un cluster Temporal |
| `gplanchat/durable-bridge-dbal` | l'exécution durable sur une base SQL | la bibliothèque, Doctrine DBAL 3 ou 4, `symfony/lock` |
| `gplanchat/durable-bridge-illuminate` | la même chose, sur la connexion que Laravel possède déjà | la bibliothèque, `illuminate/database` 11, 12 ou 13 |
| `gplanchat/durable-laravel` | le câblage Laravel : les ports liés depuis la configuration, le travail sur la file de l'application | la bibliothèque, le pont Illuminate, `illuminate/support` |
| `gplanchat/durable-magento` | un module Magento 2.4 / Mage-OS : déclaration, workers, écran d'administration | la bibliothèque ; Temporal pour tout ce qui doit survivre à un processus |
| `gplanchat/durable-plugin` | un tableau de bord Sylius pour les exécutions | le bundle, `knplabs/knp-menu` ; Sylius 2.x pour apparaître dans son menu |

Les trois ponts sont des **alternatives**, pas des couches : vous prenez Temporal, DBAL ou
Illuminate, jamais deux d'entre eux.

---

## `gplanchat/durable` — la bibliothèque

```bash
composer require gplanchat/durable
```

Le moteur et tout le domaine : `WorkflowEnvironment`, activités, minuteurs, effets de bord, signaux,
requêtes, mises à jour, workflows enfants, journal d'événements, et les objets valeur qui décrivent
les options de planification.

Une seule dépendance d'exécution — `psr/cache`, et ce pool est lui-même facultatif : il mémorise la
résolution des contrats d'activité, et `ActivityContractResolver` fonctionne sans. **Aucun
framework.** Vous pouvez piloter la bibliothèque depuis un simple script PHP, une application
Laminas, un outil en ligne de commande, ou un test.

Elle embarque un **backend en mémoire** qui fait tout tourner dans un seul processus. C'est ce
qu'emploient vos tests unitaires, et cela ne demande rien à installer.

> [!NOTE]
> Le backend en mémoire ne garde aucun état d'un processus à l'autre. Il est fait pour les tests et
> l'exploration locale, pas pour un workflow qui doit survivre à un déploiement. Voir
> [Backends](../backends/).

---

## `gplanchat/durable-bundle` — l'intégration Symfony

```bash
composer require gplanchat/durable-bundle
```

Ce qu'il fait, et que vous écririez autrement à la main :

- **L'autoconfiguration.** Les classes portant `#[AsWorkflow]` et `#[AsActivity]` s'enregistrent seules ;
  vous ne les listez pas dans un fichier de conteneur.
- **Le câblage Messenger.** Reprises de workflow et envois d'activité sont routés vers les transports
  que vous nommez dans `durable.yaml`, si bien qu'un workflow qui se suspend reprend par vos files
  existantes.
- **Les commandes de worker.** Des points d'entrée en ligne de commande pour faire tourner les
  workers de workflow et d'activité.
- **Le panneau du profileur.** Dans la barre d'outils Symfony : chaque exécution, son journal, et la
  chronologie de ses activités — avec quelle tentative a échoué, et pourquoi.

La configuration tient en un fichier, documenté clé par clé dans la
[référence de configuration](../configuration/).

---

## `gplanchat/durable-bridge-temporal` — le pilote Temporal

```bash
composer require gplanchat/durable-bridge-temporal
```

Parle à un cluster Temporal **directement en gRPC**. Il n'y a pas de SDK PHP officiel de Temporal
dans l'arbre de dépendances, et pas de RoadRunner — les définitions protobuf sont embarquées et les
workers sont de simples processus PHP.

Ce qu'il apporte par rapport au backend en mémoire :

- des exécutions qui survivent aux redémarrages de processus, aux déploiements et aux plantages ;
- des politiques de réessai côté serveur : une activité en échec est réessayée même si le worker a
  disparu ;
- les planifications cron, les attributs de recherche, et la visibilité entre processus dans
  l'interface Temporal ;
- un stockage d'événements en lecture traversante, pour que le profileur montre l'historique d'une
  vraie exécution.

Il exige `ext-grpc` et un cluster joignable. En local, une commande suffit :

```bash
temporal server start-dev --namespace durable-test --port 7233
```

> [!NOTE]
> Planifications cron et attributs de recherche sont des capacités de Temporal. Ils n'ont pas
> d'équivalent en processus, et le backend en mémoire les refuse explicitement plutôt que de les
> ignorer en silence.

---

## `gplanchat/durable-bridge-dbal` — le backend SQL

```bash
composer require gplanchat/durable-bridge-dbal
```

L'exécution durable sur **une seule base SQL**, sans cluster d'orchestration et sans `ext-grpc`. La
décision qui la porte est [**DUR030**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md).

L'interpréteur de rejeu, les ports de workflow et le tampon de commandes sont intacts : ce pont ne
fait que rendre persistants les trois stockages locaux au processus — le journal d'événements, les
métadonnées de workflow, et les liens parents des workflows enfants. Le code de workflows et
d'activités est octet pour octet celui qui tourne sur Temporal ou en mémoire.

| Conservé | Abandonné, face à Temporal |
|---|---|
| Classes de workflow, activités, `WorkflowEnvironment` | Les files de tâches distribuées — les reprises passent par Symfony Messenger |
| Signaux, requêtes, mises à jour | La planification côté serveur ; les minuteurs passent par le `DelayStamp` de Messenger |
| La sémantique d'annulation et de compensation | La sérialisation des tâches côté serveur, remplacée par un verrou applicatif |
| Le déterminisme du rejeu et le journal d'événements | La rétention d'historique, l'API de visibilité, l'interface Temporal |

À choisir quand la durabilité compte et qu'opérer un cluster ne compte pas : une base que vous
sauvegardez déjà, une migration, et aucune extension à compiler.

---

## `gplanchat/durable-bridge-illuminate` — le backend Laravel

```bash
composer require gplanchat/durable gplanchat/durable-bridge-illuminate
php artisan migrate
```

Les mêmes quatre stockages que le pont DBAL, et le même échange face à Temporal — le tableau
ci-dessus s'applique mot pour mot. Ce qui change, c'est la connexion : ceux-ci sont écrits contre
`Illuminate\Database\Connection`, le constructeur de requêtes plutôt qu'Eloquent.

C'est toute la raison d'être du paquet. **DUR030** ne paie que si l'ajout au journal et l'écriture
métier atterrissent dans **une seule transaction**, et un stockage sur `DB::connection()` est dans
`DB::transaction()` par construction. Passer à Doctrine DBAL le PDO tiré de
`DB::connection()->getPdo()` atteint la même garantie et reste un contournement ; ceci est la
réponse simple.

Les quatre tables sont livrées en migration, chargée depuis le paquet : `migrate` suffit.
`vendor:publish --tag=durable-migrations` sert à les modifier — et à partir de là, elles sont à
vous. **Gardez le nom du fichier publié** : Laravel indexe les migrations par
leur nom de base et fait gagner `database/migrations` en cas d'égalité, et c'est ce qui fait que
votre copie est celle qui tourne. Renommez-la et les deux tournent, la seconde échouant sur une
table qui existe déjà.

`Queue\ResumeLock` est la seule chose qu'aucun choix de stockage ne fournit. Deux workers qui
reprennent la **même** exécution la rejouent tous les deux, chacun croit découvrir les commandes
qu'elle produit, et ces commandes partent en double ; le journal ne l'empêche pas, il enregistre
fidèlement ce qu'on lui donne, deux fois comprises. Il prend une fermeture : un job en file, une
commande artisan ou un worker écrit à la main peuvent tous s'en servir.

> [!NOTE]
> **Il n'y a pas encore de paquet d'intégration Laravel.** Ceci est un jeu de stockages, pas un
> câblage : rien ne lie les ports, aucune commande de worker, aucun job.
> `DurableIlluminateServiceProvider` enregistre exactement une chose — où sont ses migrations. Tant
> qu'un paquet d'intégration n'existe pas, vous câblez les stockages vous-même, comme le fait une
> application sans framework.

---

## `gplanchat/durable-plugin` — le tableau de bord Sylius

```bash
composer require gplanchat/durable-plugin
```

Un tableau de bord d'administration pour Sylius : la liste des exécutions avec recherche et filtres
par statut, et une vue de détail avec les couloirs de la chronologie et les événements récents. Les
libellés de chronologie privilégient l'`ActivityType.name` lisible et ne retombent sur les
identifiants techniques que faute de mieux.

Il **observe** ; il n'exécute pas. Il exige `gplanchat/durable-bundle`, qui câble le catalogue
d'exécutions qu'il lit : la commande ci-dessus est donc toute l'installation.

> [!NOTE]
> Les données vivantes viennent du backend installé, quel qu'il soit. Aucun des trois ponts n'est un
> `require` ici — le backend est suggéré par `gplanchat/durable`, une fois, pour toutes les
> intégrations. Sans backend, le plugin s'installe quand même, la route et l'entrée de menu
> fonctionnent, et le tableau de bord affiche son état dégradé au lieu d'exécutions vivantes.

## `gplanchat/durable-magento` — l'intégration Magento

```bash
composer require gplanchat/durable-magento
```

> [!WARNING]
> **Pas encore publié.** Le paquet existe dans le dépôt et tourne sur le banc ; il n'est pas sur
> Packagist, donc la commande ci-dessus ne résout pas aujourd'hui. Ce qui suit décrit ce qui est
> construit, pas ce que vous pouvez installer.

Un module Magento 2.4 / Mage-OS — `Gplanchat_DurableModule` dans `bin/magento module:status`. Il déclare
les classes de workflow et d'activité au moteur, l'assemble pour un processus Magento, livre les
workers en commandes `bin/magento`, et ajoute un écran d'administration en lecture seule sous
**System > Durable processes > Process history**.

L'écran est une grille Magento standard — pagination, signets, choix des colonnes, export, et un
filtre d'état multi-select dont les options viennent de l'énumération elle-même. Ouvrir une
exécution mène à son détail : une frise avec **une ligne par action** — une activité planifiée,
démarrée puis terminée est une ligne, et la barre de la ligne est sa durée. L'exécution elle-même
est la première ligne, nommée d'après le workflow et portant ses tâches de workflow ; un workflow
enfant garde sa propre ligne. Chaque barre est découpée entre événements consécutifs, de sorte
qu'un intervalle sans rien d'enregistré — l'attente d'un worker — dit sa durée au lieu de se
cacher dans une barre. Le journal est en dessous. Chaque ligne du journal se
déplie sur ce que le backend a enregistré avec elle — les arguments d'appel d'une activité, ce
qu'elle a rendu, la classe et le message d'un échec. Placer dans le temps plutôt que par rang est
tout l'intérêt : c'est ce qui fait qu'une exécution ayant passé vingt-deux de ses vingt-quatre
secondes à attendre en a l'air.

Le conteneur de Magento n'a pas d'équivalent de l'autoconfiguration par tag de Symfony : la
déclaration est explicite, deux tableaux dans `di.xml`.

Ce qui ne se déclare **pas**, c'est le contrat : la fabrique lit les interfaces de chaque
gestionnaire et garde celles qui portent `#[AsActivityMethod]`. Une déclaration de moins à écrire de
travers, et les noms d'activité restent ceux des attributs.

**Deux backends, et c'est Composer qui l'impose.** Magento atteint la mémoire et Temporal, et le
module déclare un `conflict` sur les deux ponts SQL — `Magento\Framework\App\ResourceConnection`
n'est ni une connexion Doctrine DBAL ni celle d'Illuminate. Lequel des deux vous obtenez se décide
par un DSN dans `app/etc/env.php`, pas par un réglage :

```php
'durable' => [
    'temporal' => ['dsn' => 'temporal://temporal:7233?namespace=default&tls=0'],
],
```

Sans lui, le journal vit dans le processus qui l'écrit et meurt avec lui — acceptable pour une
commande en ligne, ruineux pour le reste.

**Les workers sont des commandes, pas des consommateurs de file**, et un exploitant les supervise
comme n'importe quel processus long :

```bash
bin/magento durable:worker --role=journal   --time-limit=3600
bin/magento durable:worker --role=activity  --time-limit=3600
```

Un processus, une file, un rôle : ce sont deux files Temporal distinctes, dont le parallélisme se
règle séparément. Rien ne circule sur le `MessageQueue` de Magento — sur Temporal une activité est
une commande Temporal et une reprise une tâche de workflow, donc un topic ici serait une seconde
file à superviser, pour rien.

> [!NOTE]
> Démarrez les exécutions **sur la grappe**, pas dans la requête qui les déclenche. Un observateur
> sur `sales_order_place_after` qui appelle `RuntimeFactory::workflowClient()->startAsync()` confie
> l'exécution à Temporal et rend la main ; la démarrer en ligne la tuerait avec la requête, ce qui
> est précisément la panne que cette intégration existe pour retirer.

---

## Qu'est-ce que j'installe ?

Chaque commande ci-dessous est celle que le sélecteur de la [page d'accueil](/fr/) vous donne,
écrite en toutes lettres.

| Votre situation | Commande |
|---|---|
| Découverte, ou tests unitaires seulement | `composer require gplanchat/durable` |
| Sans framework, une base SQL | `composer require gplanchat/durable gplanchat/durable-bridge-dbal` |
| Sans framework, un cluster Temporal | `composer require gplanchat/durable gplanchat/durable-bridge-temporal` |
| Symfony, tests seulement | `composer require gplanchat/durable-bundle` |
| Symfony, une base SQL | `composer require gplanchat/durable-bundle gplanchat/durable-bridge-dbal` |
| Symfony, un cluster Temporal | `composer require gplanchat/durable-bundle gplanchat/durable-bridge-temporal` |
| Sylius, tests seulement | `composer require gplanchat/durable-plugin` |
| Sylius, une base SQL | `composer require gplanchat/durable-plugin gplanchat/durable-bridge-dbal` |
| Sylius, un cluster Temporal | `composer require gplanchat/durable-plugin gplanchat/durable-bridge-temporal` |
| Laravel, une base SQL | `composer require gplanchat/durable gplanchat/durable-bridge-illuminate` |
| Magento, grappe Temporal | `composer require gplanchat/durable-magento gplanchat/durable-bridge-temporal` |

Chaque ligne ne nomme que l'intégration : le bundle tire la bibliothèque, et le plugin tire le
bundle. Sans framework, vous nommez la bibliothèque vous-même, et vous câblez aussi les workers
vous-même.

La ligne Laravel nomme la bibliothèque plutôt qu'une intégration, et c'est désormais un *choix* et
non un manque : `gplanchat/durable-laravel` existe — un service provider qui lie les quatre ports de
stockage, des workflows déclarés dans `config/durable.php`, le travail sur la file que l'application
draine déjà. Tant qu'il n'est pas tagué, le pont s'installe seul et vous le câblez vous-même ; la
section ci-dessus dit ce que l'intégration vous retire des mains.

---

## Un seul code, un seul comportement

Tous les backends font tourner le **même pilote à fibres** et le **même chemin d'exécution des
activités**. Un workflow que vous avez testé en mémoire se comporte de la même façon contre DBAL ou
contre Temporal — décompte des réessais, classification des échecs, annulation et compensation
compris.

Là où une capacité n'a réellement pas d'équivalent, le backend **échoue avec un message explicite**
plutôt que de faire semblant. Les différences sont listées dans
[Backends](../backends/#capability-matrix).

---

## Monorepo et publications

Le développement se fait dans un seul dépôt, `gplanchat/durable-dev`. Chaque paquet est publié dans
son propre dépôt en lecture seule par une scission, si bien qu'un `composer require` tire un petit
paquet plutôt que tout l'arbre.
