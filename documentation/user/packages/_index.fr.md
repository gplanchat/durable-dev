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
| `gplanchat/durable-bundle` | câblage Symfony, transports Messenger, panneau du profileur | la bibliothèque et Symfony Messenger |
| `gplanchat/durable-bridge-temporal` | le pilote Temporal, en gRPC | la bibliothèque, `ext-grpc`, un cluster Temporal |
| `gplanchat/durable-bridge-dbal` | l'exécution durable sur une base SQL | la bibliothèque, Doctrine DBAL 3 ou 4, `symfony/lock` |
| `gplanchat/durable-bridge-illuminate` | la même chose, sur la connexion que Laravel possède déjà | la bibliothèque, `illuminate/database` 11, 12 ou 13 |
| `gplanchat/durable-laravel` | le câblage Laravel : les ports liés depuis la configuration, le travail sur la file de l'application | la bibliothèque, le pont Illuminate, `illuminate/support` |
| `gplanchat/durable-magento` | un module Magento 2.4 / Mage-OS : déclaration, workers, écran d'administration | la bibliothèque ; Temporal pour tout ce qui doit survivre à un processus |
| `gplanchat/durable-plugin` | un tableau de bord Sylius pour les exécutions | le bundle, `knplabs/knp-menu` ; Sylius 2.x pour apparaître dans son menu |
| `gplanchat/durable-phpstan` | l'analyse statique des appels de stub face à leur contrat | la bibliothèque, `phpstan/phpstan` |
| `gplanchat/durable-rector` | la migration automatisée depuis le SDK PHP de Temporal | la bibliothèque, `rector/rector` |

Les trois ponts sont des **alternatives**, pas des couches : vous prenez Temporal, DBAL ou
Illuminate, jamais deux d'entre eux.

Les deux derniers sont des **outils de développement**, en `require-dev` plutôt qu'en `require` :

- **`gplanchat/durable-phpstan`** résout les appels d'`activityStub()` et de `childWorkflowStub()`
  face à l'interface de contrat : une activité mal nommée ou un mauvais argument devient une erreur
  d'analyse au lieu d'un échec de sérialisation à l'exécution.
- **`gplanchat/durable-rector`** migre un projet depuis le SDK PHP officiel de Temporal — la
  réécriture des attributs et le changement de modèle d'exécution, en gardant les noms de type de
  workflow et d'activité qu'un serveur en cours d'exécution connaît déjà. Ce qu'il ne sait pas
  convertir, il le commente, pour que vous le sachiez avant de commencer. Voir
  [la page de comparaison](../comparison/#choisir).

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

- **L'autoconfiguration.** Les classes portant `#[AsWorkflow]` ou `#[AsActivityHandler]`
  s'enregistrent seules ; vous ne les listez pas dans un fichier de conteneur, et vous ne les
  balisez pas non plus. `#[AsActivity]` est un attribut de nommage posé sur le contrat, pas
  d'enregistrement.
- **Le câblage Messenger.** Reprises de workflow et envois d'activité sont routés vers les transports
  que vous nommez dans `durable.yaml`, si bien qu'un workflow qui se suspend reprend par vos files
  existantes.
- **Une commande de console.** `durable:execution:diagnose <executionId>` affiche ce que le moteur
  détient d'une exécution : ses métadonnées de workflow, ses liens parent/enfant et son journal
  d'événements. Il n'y a pas de commande de worker à ajouter — le worker, c'est le
  `messenger:consume` de Messenger sur les transports ci-dessus.
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
> **Ce pont est la moitié stockage, pas un câblage.** Rien ici ne lie les ports, et il n'y a ni
> commande de worker ni job : `DurableIlluminateServiceProvider` enregistre exactement une chose —
> où sont ses migrations. Ce qui le lie, c'est
> [`gplanchat/durable-laravel`](#gplanchatdurable-laravel--lintégration-laravel), la section
> ci-dessous. Le pont seul, et vous câblez les stockages vous-même, comme le fait une application
> sans framework.

---

## `gplanchat/durable-laravel` — l'intégration Laravel

```bash
composer require gplanchat/durable-laravel
php artisan migrate
php artisan vendor:publish --tag=durable-config
```

L'auto-discovery enregistre le provider. Il lie les quatre ports de stockage, les jobs d'activité et
de reprise, et le verrou par exécution, depuis un seul `config/durable.php` publié.

**Un choix de backend lie tous les ports.** Un journal sur un backend sous un catalogue sur un autre
n'est pas une configuration, c'est une panne : `backend` est une seule valeur, et un backend que ce
paquet ne sert pas est refusé **par son nom** à l'enregistrement, en nommant les deux qu'il sert —
`illuminate` et `memory`.

**Les workflows sont déclarés, pas scannés.** Laravel n'a pas d'équivalent de l'autoconfiguration
par attribut de Symfony, donc la clé `workflows` nomme les classes. Mesuré : les nommer coûte
0,14 ms et ne grandit pas avec l'application, là où un scan par réflexion coûte 15 ms à mille classes
**et les charge toutes dans chaque processus** pour en trouver cinq. Pas de `durable:cache` pour la
même raison — `config:cache` met déjà en cache le fichier qu'il dupliquerait.

**Le travail voyage sur la file que l'application draine déjà**, avec `php artisan queue:work` pour
seul worker. Activités et reprises sont des jobs ; un minuteur est une reprise différée sur le délai
natif de la file.

### Ce n'est pas un moteur durable pour Laravel, et ce carré est pris

[`durable-workflow/workflow`](https://github.com/durable-workflow/workflow) — anciennement
`laravel-workflow/laravel-workflow` — c'est de l'exécution durable **sur les files de Laravel** :
`yield` comme point de reprise, son propre stockage, aucun serveur, explicitement inspiré de Temporal
et d'Azure Durable Functions, mille étoiles et plus. Il est bon à ce qu'il fait, et si un moteur sur
votre file existante est ce que vous cherchez, prenez-le.

Ce que ce paquet vend, c'est le **choix du backend** : le même code de workflow contre un cluster
Temporal *ou* contre une seule base SQL, et un parc mixte Symfony / Sylius / Laravel partageant un
seul moteur. Une classe de workflow écrite pour `gplanchat/durable-bundle` tourne ici sans
modification — c'est toute la promesse, et c'est celle que l'autre paquet ne fait pas.

Deux noms voisins sur Packagist méritent la phrase plutôt que l'espoir que personne ne remarque.

### Nexus, sur le backend qui sait le router

Servir une opération Nexus, c'est répondre à un appel venu d'un autre espace de noms, et seul le
cluster route ces appels-là. La clé `nexus.handlers` nomme les gestionnaires et les contrats qu'ils
servent :

```php
'nexus' => ['handlers' => [App\Nexus\BillingHandler::class => App\Contracts\BillingService::class]],
```

Ce qu'un gestionnaire ne sert pas, un workflow le remplit — il porte `#[FulfilsNexusOperation]`, et
il suffit qu'il soit dans la liste `workflows` ci-dessus. Un contrat se sépare en deux interfaces
parce que PHP ne sait pas dire « implémente partiellement », et le registre est ce qui recolle les
deux moitiés.

**En déclarer un sous un backend qui ne route pas est refusé à l'enregistrement**, pas au premier
appel — et le backend est nommé. Appeler une opération Nexus ne déclare rien ici : c'est l'affaire
du workflow, et c'est le cas le plus courant.

`php artisan durable:nexus-worker` draine les opérations que le cluster route vers cette
application.

### Trois réglages refusés plutôt que tolérés

| réglage | refusé | pourquoi |
|---|---|---|
| `lock.store: null` | toujours | il accorde tous les verrous, dans tous les déploiements |
| `lock.store: array` | sous `illuminate` | une reprise tourne dans un worker séparé de celui qui l'a dispatchée, donc deux verrous `array` ne se voient jamais — quinze sections critiques chevauchées sur vingt, mesurées |
| la connexion de file `sync` | sous `illuminate` | elle exécute les jobs sur place : une reprise qui en dispatche une autre récurse jusqu'à la pile |

`array` reste accepté sous `memory` : c'est le cache de test par défaut de Laravel, et exclure dans
un seul processus est exactement ce qu'un test veut.

### Deux choses qui ressemblent à des bugs et n'en sont pas

**Le driver `sqlite` ne peut pas héberger plus d'un worker.** Quatre workers qui dépilent la table
`jobs` donnent `SQLSTATE[HY000]: General error: 5 database is locked`, et trois sur quatre meurent à
leur premier job — WAL activé et `busy_timeout` à 60 s. Passez à MySQL, PostgreSQL ou Redis dès qu'il
y a un second worker.

**Le job d'un worker tué reste réservé jusqu'à `retry_after`** — 90 secondes par défaut. Un worker
lancé avec `--stop-when-empty` dans cette fenêtre voit une file vide et sort **sans rien faire**, ce
qui ressemble trait pour trait à une reprise qui a échoué. C'en est une qui n'a pas encore reçu le
job : un worker supervisé, qui survit à la fenêtre, le reprend et l'exécution se termine.

### Pas dans ce paquet

**Rien concernant Temporal** — il est servi. `backend: 'temporal'` met le journal et le catalogue
d'exécutions dans le cluster, et `php artisan durable:temporal-worker` draine les tâches de workflow,
la seule chose que la file de l'application ne peut pas porter.

`gplanchat/durable-bridge-temporal` est **suggéré et non exigé** : il installe huit paquets, dont cinq
composants Symfony qu'une application Laravel ne charge jamais, pour quelque 36 Mo. Une application
qui ne choisit pas ce backend ne le paie jamais, et celle qui le choisit s'entend nommer le paquet à
installer. Scinder le pont — sa partie couplée à Symfony fait huit fichiers sur 774 — retirerait le
poids, et c'est un change à part.

**Un tableau de bord.** `gplanchat/durable-filament` exigera ce paquet, et ce paquet n'exigera, ne
suggérera ni ne détectera jamais Filament.

---

## `gplanchat/durable-plugin` — le tableau de bord Sylius

```bash
composer require gplanchat/durable-plugin
```

L'habillage Sylius du [tableau de bord](../dashboard/) : une entrée dans le menu d'administration, la
liste des exécutions sur des cartes Tabler avec pagination par curseur, et le détail à côté. Les
panneaux, le regroupement et les mots viennent de `gplanchat/durable` lui-même : la même exécution se
lit donc pareil ici et sur l'écran Magento — ce que ce paquet possède, c'est l'habillage autour d'eux.

Les libellés privilégient l'`ActivityType.name` lisible et ne retombent sur les identifiants
techniques que faute de mieux.

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

Un module Magento 2.4 / Mage-OS — `Gplanchat_DurableModule` dans `bin/magento module:status`. Il déclare
les classes de workflow et d'activité au moteur, l'assemble pour un processus Magento, livre les
workers en commandes `bin/magento`, et ajoute un écran d'administration en lecture seule sous
**System > Durable processes > Process history**.

L'habillage est celui de Magento : une grille standard — pagination, signets, choix des colonnes,
export, et un filtre d'état multi-select dont les options viennent de l'énumération elle-même — avec
l'état du backend et les compteurs par issue au-dessus. Ce que l'écran *montre* n'appartient ni à
Magento ni à ce paquet : voir [le tableau de bord](../dashboard/), que chaque hôte rend dans son
propre habillage.

Le conteneur de Magento n'a pas d'équivalent de l'autoconfiguration par tag de Symfony : la
déclaration est explicite, deux tableaux dans `di.xml` :

```xml
<type name="Gplanchat\DurableModule\Runtime\RuntimeFactory">
    <arguments>
        <argument name="workflowClasses" xsi:type="array">
            <item name="place_order" xsi:type="string">Acme\Shop\Workflow\PlaceOrder</item>
        </argument>
        <argument name="activityHandlers" xsi:type="array">
            <item name="order" xsi:type="object">Acme\Shop\Activity\OrderActivities</item>
        </argument>
    </arguments>
</type>
```

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

**Deux processus, et en oublier un ne coûte pas la même chose des deux côtés.** Sans
`--role=journal`, rien n'avance : les exécutions démarrent, leur historique se remplit, et personne
ne répond à leurs tâches de workflow. Sans `--role=activity`, c'est pire, parce que ça a l'air de
marcher — une exécution avance **jusqu'à sa première activité** et s'y arrête, la commande débitée
et le stock non, et c'est le client qui vous l'apprend. C'est la panne que cette intégration existe
pour supprimer, remise en place à la main.

Les bornes `--time-limit` et `--max-tasks` sont pour le superviseur : elles font finir le processus
pour que ce qui le relance puisse le relancer. Et les reprises sont l'affaire de la grappe — les
tentatives d'une activité sont ordonnancées que quelqu'un écoute ou non, donc une exécution dont
l'activité « a échoué après 3 tentatives » en quelques secondes signale un worker absent, pas un
code qui se trompe trois fois de suite.

⚠ **Les réglages de file de Magento n'entrent pas là-dedans.** `retry_inprogress_after`, les tâches
cron `messagequeue_*`, `queue_lock` — aucun ne porte quoi que ce soit de Durable, puisque rien de
Durable ne circule sur `MessageQueue`. Réglez-les pour vos propres consommateurs.

> [!NOTE]
> Démarrez les exécutions **sur la grappe**, pas dans la requête qui les déclenche. Un observateur
> sur `sales_order_place_after` qui appelle `RuntimeFactory::workflowClient()->startAsync()` confie
> l'exécution à Temporal et rend la main ; la démarrer en ligne la tuerait avec la requête, ce qui
> est précisément la panne que cette intégration existe pour retirer.

---

## Qu'est-ce que j'installe ?

Chaque commande ci-dessous est celle que le sélecteur de la [page d'accueil](/fr/) vous donne,
écrite en toutes lettres.

Le sélecteur lit son état dans l'URL : un lien peut donc ouvrir la page avec une situation déjà
choisie — pratique dans un ticket, un README ou une réponse de support :

```
https://durable.rocks/fr/?fw=magento&be=temporal#install
```

`fw` est le framework (`none`, `symfony`, `laravel`, `sylius`, `apiplatform`, `magento`), `be`
l'endroit où vit l'état (`memory`, `temporal`, `dbal`, `illuminate`), et `dist` la base sous une
distribution (`none`, `symfony`, `laravel`). Chaque axe est facultatif. Une valeur que le sélecteur
refuse — un framework qui n'est pas publié, un backend que l'appariement interdit — est **ignorée
plutôt que forcée** : un vieux lien retombe sur le choix par défaut au lieu d'afficher une
combinaison qui n'existe pas. Et choisir dans la page réécrit la barre d'adresse, donc le lien à
partager est celui qu'on a déjà sous les yeux.

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
