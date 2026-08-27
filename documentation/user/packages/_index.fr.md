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
| `gplanchat/durable-plugin` | un tableau de bord Sylius pour les exécutions | le bundle, `knplabs/knp-menu` ; Sylius 2.x pour apparaître dans son menu |

Les deux ponts sont des **alternatives**, pas des couches : vous prenez Temporal ou DBAL, jamais les
deux.

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

- **L'autoconfiguration.** Les classes portant `#[Workflow]` et `#[Activity]` s'enregistrent seules ;
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
> Les données vivantes viennent du backend installé, quel qu'il soit. Aucun des deux ponts n'est un
> `require` ici — le backend est suggéré par `gplanchat/durable`, une fois, pour toutes les
> intégrations. Sans backend, le plugin s'installe quand même, la route et l'entrée de menu
> fonctionnent, et le tableau de bord affiche son état dégradé au lieu d'exécutions vivantes.

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

Chaque ligne ne nomme que l'intégration : le bundle tire la bibliothèque, et le plugin tire le
bundle. Sans framework, vous nommez la bibliothèque vous-même, et vous câblez aussi les workers
vous-même.

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
