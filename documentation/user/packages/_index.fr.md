---
title: Paquets
weight: 5
---

# Paquets

Durable se compose d'une bibliothèque centrale, d'une intégration de framework facultative et d'un
backend à choisir. Le backend est l'endroit où vit le journal d'une exécution et ce qui planifie son
travail ; le journal est la suite d'événements, en ajout seul, qui enregistre chaque étape d'une
exécution (voir le [glossaire](../glossary/)). Installez ce dont vous avez besoin : la bibliothèque
seule suffit pour écrire un workflow et le tester unitairement, et les paquets qui s'ajoutent
au-dessus changent seulement l'endroit où l'exécution est enregistrée, jamais le code du workflow.

| Paquet | Apporte | Exige |
|---|---|---|
| `gplanchat/durable` | workflows, activités, minuteurs, journal d'événements, backend en mémoire | `psr/cache` |
| `gplanchat/durable-bundle` | câblage Symfony, transports Messenger, panneau du profileur | la bibliothèque et Symfony Messenger |
| `gplanchat/durable-bridge-temporal` | le pilote Temporal, en gRPC | la bibliothèque, `ext-grpc`, un cluster Temporal |
| `gplanchat/durable-bridge-dbal` | l'exécution durable sur une base SQL | la bibliothèque, Doctrine DBAL 3 ou 4, `symfony/lock` |
| `gplanchat/durable-bridge-illuminate` | la même chose, par la couche de base de données de Laravel | la bibliothèque, `illuminate/database` 11, 12 ou 13 |
| `gplanchat/durable-laravel` | le câblage Laravel : les ports liés depuis la configuration, le travail sur la file de l'application | la bibliothèque, le pont Illuminate, `illuminate/support` |
| `gplanchat/durable-magento` | un module Magento 2.4 / Mage-OS : déclaration, workers, écran d'administration | la bibliothèque ; Temporal pour tout ce qui doit survivre à un processus |
| `gplanchat/durable-plugin` | un tableau de bord Sylius pour les exécutions | le bundle, `knplabs/knp-menu` ; Sylius 2.x pour apparaître dans son menu |
| `gplanchat/durable-filament` | un tableau de bord des exécutions dans un panneau Filament | l'intégration Laravel, Filament 3 ou 4 |
| `gplanchat/durable-phpstan` | l'analyse statique des appels de stub face à leur contrat | la bibliothèque, `phpstan/phpstan` |
| `gplanchat/durable-rector` | la migration automatisée depuis le SDK PHP de Temporal | la bibliothèque, `rector/rector` |

Un workflow est la classe PHP qui décrit les étapes d'une exécution, et une activité est une unité
d'effet de bord qu'un workflow appelle, comme un appel HTTP ou une écriture en base.

Les trois ponts sont des **alternatives** et ne se superposent pas : vous installez Temporal, DBAL ou Illuminate, jamais deux
d'entre eux.

Les deux derniers paquets sont des **outils de développement** et se placent en `require-dev` :

- **`gplanchat/durable-phpstan`** résout les appels d'`activityStub()` et de `childWorkflowStub()`
  face à l'interface de contrat. Une activité mal nommée ou un mauvais argument apparaît alors comme
  une erreur d'analyse, au lieu d'un échec de sérialisation à l'exécution. Il vérifie aussi qu'un
  [paramètre `#[Activities]`](../workflows/#arguments-durable-supplies) et son docblock
  `@param ActivityStub<Contrat>` nomment le même contrat.
- **`gplanchat/durable-rector`** migre un projet depuis le SDK PHP officiel de Temporal. Il réécrit
  les attributs et change le modèle d'exécution, en conservant les noms de type de workflow et
  d'activité qu'un serveur en cours d'exécution a déjà enregistrés. Il pose un commentaire sur
  chaque construction qu'il ne peut pas convertir, pour que vous les voyiez avant de commencer. Voir
  [la page de comparaison](../comparison/#choisir).

---

## `gplanchat/durable`, la bibliothèque {#gplanchatdurable--la-bibliothèque}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable
```

La bibliothèque contient le moteur et tout le domaine : `WorkflowEnvironment`, activités,
minuteurs, effets de bord, signaux, requêtes, mises à jour, workflows enfants, journal
d'événements, et les objets valeur qui décrivent les options de planification.

Elle a une seule dépendance d'exécution, `psr/cache`, et le pool de cache lui-même est facultatif :
il mémorise la résolution des contrats d'activité, et `ActivityContractResolver` fonctionne sans.
La bibliothèque n'exige **aucun framework**. Vous pouvez la piloter depuis un simple script PHP, une
application Laminas, un outil en ligne de commande ou un test.

Elle inclut un **backend en mémoire** qui fait tout tourner dans un seul processus. Vos tests
unitaires l'utilisent, et il ne demande rien d'autre à installer.

> [!NOTE]
> Le backend en mémoire ne garde aucun état d'un processus à l'autre. Utilisez-le pour les tests et
> l'exploration locale ; un workflow qui doit survivre à un déploiement a besoin d'un autre backend.
> Voir [Backends](../backends/).

---

## `gplanchat/durable-bundle`, l'intégration Symfony {#gplanchatdurable-bundle--lintégration-symfony}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-bundle
```

Le bundle prend en charge ce que vous écririez sinon à la main :

- **L'autoconfiguration.** Le bundle enregistre chaque classe qui porte `#[AsWorkflow]` ou
  `#[AsActivityHandler]`. Vous ne listez ces classes dans aucun fichier de conteneur, et vous ne les
  balisez pas. `#[AsActivity]` nomme le contrat et n'enregistre rien.
- **Le câblage Messenger.** Les reprises de workflow et les envois d'activité partent vers les
  transports que vous nommez dans `durable.yaml` : un workflow qui se suspend reprend donc par vos
  files existantes.
- **Une commande de console.** `durable:execution:diagnose <executionId>` affiche ce que le moteur
  détient d'une exécution : ses métadonnées de workflow, ses liens parent/enfant et son journal
  d'événements. Le bundle n'ajoute aucune commande de worker ; le worker est le `messenger:consume`
  de Messenger sur les transports ci-dessus.
- **Le panneau du profileur.** Dans la barre d'outils Symfony, le panneau montre chaque exécution,
  son journal et la chronologie de ses activités, avec la tentative qui a échoué et la raison.

Toute la configuration tient dans un fichier, documenté clé par clé dans la
[référence de configuration](../configuration/).

---

## `gplanchat/durable-bridge-temporal`, le pilote Temporal {#gplanchatdurable-bridge-temporal--le-pilote-temporal}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-bridge-temporal
```

Le pont parle à un cluster Temporal **directement en gRPC**. L'arbre de dépendances ne contient ni
le SDK PHP officiel de Temporal ni RoadRunner : les définitions protobuf sont embarquées et les
workers sont de simples processus PHP.

Par rapport au backend en mémoire, il apporte :

- des exécutions qui survivent aux redémarrages de processus, aux déploiements et aux plantages ;
- des politiques de réessai côté serveur : une activité en échec est réessayée même si le worker a
  disparu ;
- les planifications cron, les attributs de recherche, et la visibilité entre processus dans
  l'interface Temporal ;
- un stockage d'événements en lecture traversante, pour que le profileur montre l'historique d'une
  vraie exécution.

Il exige `ext-grpc` et un cluster joignable. En local, une commande démarre un serveur de
développement :

```bash
temporal server start-dev --namespace durable-test --port 7233
```

> [!NOTE]
> Les planifications cron et les attributs de recherche sont des capacités de Temporal sans
> équivalent en processus. Le backend en mémoire les rejette avec une erreur explicite au lieu de
> les ignorer en silence.

---

## `gplanchat/durable-bridge-dbal`, le backend SQL {#gplanchatdurable-bridge-dbal--le-backend-sql}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-bridge-dbal
```

Le pont fournit l'exécution durable sur **une seule base SQL**, sans cluster d'orchestration et sans
`ext-grpc`. [**DUR030**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md)
consigne la décision qui le fonde.

Le pont laisse intacts l'interpréteur de rejeu, les ports de workflow et le tampon de commandes. Le
rejeu est la façon dont une exécution reprend : le code du workflow tourne à nouveau depuis sa
première ligne, et chaque étape enregistrée renvoie son résultat depuis le journal. Le pont rend
seulement persistants trois stockages locaux au processus : le journal d'événements, les
métadonnées de workflow, et les liens parents des workflows enfants. Le code de workflows et
d'activités est octet pour octet celui qui tourne sur Temporal ou en mémoire.

| Conservé | Abandonné par rapport à Temporal |
|---|---|
| Classes de workflow, activités, `WorkflowEnvironment` | Les files de tâches distribuées ; les reprises passent par Symfony Messenger |
| Signaux, requêtes, mises à jour | La planification côté serveur ; les minuteurs passent par le `DelayStamp` de Messenger |
| La sémantique d'annulation et de compensation | La sérialisation des tâches côté serveur, remplacée par un verrou applicatif |
| Le déterminisme du rejeu et le journal d'événements | La rétention d'historique, l'API de visibilité, l'interface Temporal |

Choisissez-le quand vous avez besoin de durabilité sans opérer de cluster. Il demande une base que
vous sauvegardez déjà, une migration, et aucune extension à compiler.

---

## `gplanchat/durable-bridge-illuminate`, le backend Laravel {#gplanchatdurable-bridge-illuminate--le-backend-laravel}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable gplanchat/durable-bridge-illuminate
php artisan migrate
```

Ce pont fournit les mêmes quatre stockages que le pont DBAL, avec les mêmes compromis face à
Temporal : le tableau ci-dessus s'applique mot pour mot. La connexion change. Ces stockages
utilisent `Illuminate\Database\Connection` et son constructeur de requêtes, sans Eloquent.

Donnez aux stockages leur propre connexion dans `config/database.php`, distincte de la connexion par
défaut de l'application (DUR054). Sur une connexion partagée, les transactions propres à Durable
s'imbriquent dans celles de l'application : un rollback métier efface des événements du journal, et
une prise de main reste invisible aux autres workers tant que le code métier n'a pas validé. Pour
traiter une activité qui écrit puis meurt, rendez l'activité idempotente. Ne partagez jamais une
transaction avec le code métier à cette fin.

Les quatre tables sont livrées en migration, chargée directement depuis le paquet : `migrate`
suffit. Pour les modifier, publiez-les avec `vendor:publish --tag=durable-migrations` ; à partir de
là, vous maintenez la copie publiée. **Gardez le nom du fichier publié.** Laravel indexe les
migrations par leur nom de base et donne la priorité à `database/migrations` quand deux noms
coïncident, ce qui fait de votre copie celle qui s'exécute. Si vous la renommez, les deux migrations
s'exécutent, et la seconde échoue sur une table qui existe déjà.

`Queue\ResumeLock` couvre ce qu'aucun choix de stockage ne fournit. Quand deux workers reprennent la
**même** exécution, tous deux la rejouent, tous deux traitent les commandes qu'elle produit comme
nouvelles, et ces commandes partent en double. Le journal ne l'empêche pas, car il enregistre tout
ce qu'il reçoit, doublons compris. `ResumeLock` prend une fermeture : un job en file, une commande
artisan ou un worker écrit à la main peuvent tous s'en servir.

> [!NOTE]
> **Ce pont fournit seulement le stockage.** Il ne lie aucun port et ne livre ni commande de worker
> ni job ; `DurableIlluminateServiceProvider` enregistre seulement l'emplacement des migrations.
> [`gplanchat/durable-laravel`](#gplanchatdurable-laravel--lintégration-laravel), décrit dans la
> section suivante, lie les stockages. Si vous installez le pont seul, vous câblez les stockages
> vous-même, comme le fait une application sans framework.

---

## `gplanchat/durable-laravel`, l'intégration Laravel {#gplanchatdurable-laravel--lintégration-laravel}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-laravel
php artisan migrate
php artisan vendor:publish --tag=durable-config
```

L'auto-discovery des paquets enregistre le provider. À partir d'un seul `config/durable.php` publié,
le provider lie les quatre ports de stockage, les jobs d'activité et de reprise, et le verrou par
exécution.

**Une seule valeur `backend` lie tous les ports.** Un journal sur un backend avec un catalogue
d'exécutions sur un autre est une panne : `backend` prend donc une seule valeur. Une valeur que ce
paquet ne sert pas fait échouer l'enregistrement, avec une erreur qui la nomme et nomme les deux
backends que le paquet sert : `illuminate` et `memory`.

**Vous déclarez les workflows dans la configuration.** Laravel n'a pas d'équivalent de
l'autoconfiguration par attribut de Symfony : la clé `workflows` nomme donc les classes. Les nommer
coûte 0,14 ms, mesuré, et ce coût ne grandit pas avec l'application. Un scan par réflexion coûte
15 ms à mille classes **et les charge toutes dans chaque processus** pour en trouver cinq. Pour la
même raison, il n'y a pas de `durable:cache` : `config:cache` met déjà en cache le fichier qu'il
dupliquerait.

**Le travail passe par la file que l'application draine déjà**, avec `php artisan queue:work` pour
seul worker. Activités et reprises sont des jobs ; un minuteur est un job de déclenchement différé
qui utilise le délai natif de la file.

### Comparaison avec `durable-workflow/workflow` {#ce-nest-pas-un-moteur-durable-pour-laravel-et-ce-carré-est-pris}

[`durable-workflow/workflow`](https://github.com/durable-workflow/workflow), anciennement
`laravel-workflow/laravel-workflow`, fournit l'exécution durable **sur les files de Laravel**, avec
son propre stockage. Il s'inspire explicitement de Temporal et d'Azure Durable Functions et compte
plus de mille étoiles. Depuis la 2.0, les workflows s'y écrivent en méthodes linéaires portées par
des Fibers, et il tourne au choix intégré à votre application, sur son propre serveur autonome ou
sur son Cloud géré, avec des SDK PHP, Python et Rust. Il livre une interface de suivi, Waterline. Il
fait bien son travail ; si vous cherchez un moteur pensé d'abord pour Laravel, choisissez-le.

`gplanchat/durable-laravel` propose un **autre choix de backend**. Le même code de workflow tourne
contre un cluster Temporal (Temporal Cloud et Nexus compris, avec un historique que l'interface de
Temporal lit) *ou* contre une base SQL, sans cluster à opérer. Un parc mixte Symfony / Sylius /
Laravel partage aussi un seul moteur : une classe de workflow écrite pour `gplanchat/durable-bundle`
tourne ici sans modification. Ces deux points sont toute la promesse du paquet, et
`durable-workflow/workflow` ne la fait pas.

Cette section existe parce que les deux paquets portent des noms voisins sur Packagist.

### Démarrer une exécution

`WorkflowResumeDispatcher::dispatchNewWorkflowRun()` démarre une exécution sur chaque backend :

- sur `illuminate`, il met en file la première reprise pour `queue:work` ;
- sur `temporal`, il démarre le workflow sur le cluster, qui livre tout ce qui suit ;
- sur `memory`, il mène l'exécution **dans le processus appelant** : l'appel rend la main une fois
  l'exécution terminée, ou quand elle attend un signal ou une échéance au-delà du budget de dix
  secondes. Le journal de ce backend vit dans le processus : rien hors du processus ne peut faire
  avancer l'exécution.

### Servir des opérations Nexus {#nexus-sur-le-backend-qui-sait-le-router}

Une opération Nexus est une opération servie par un autre service, avec son propre contrat, qu'un
workflow appelle comme il appelle une activité (voir le [glossaire](../glossary/)). En servir une,
c'est répondre à un appel venu d'un autre espace de noms, et seul le cluster route ces appels. La
clé `nexus.handlers` nomme les gestionnaires et les contrats qu'ils servent :

```php
'nexus' => ['handlers' => [App\Nexus\BillingHandler::class => App\Contracts\BillingService::class]],
```

Un workflow remplit les opérations qu'aucun gestionnaire ne sert. Il porte
`#[FulfilsNexusOperation]`, et il suffit qu'il figure dans la liste `workflows` ci-dessus. Un
contrat se sépare en deux interfaces parce que PHP ne permet pas d'exprimer une implémentation
partielle ; le registre recolle les deux moitiés.

**Une déclaration Nexus sous un backend qui ne route pas fait échouer l'enregistrement**, avant le
premier appel, avec une erreur qui nomme le backend. Appeler une opération Nexus ne demande aucune
déclaration ici : c'est le workflow qui fait l'appel, et c'est le cas le plus courant.

`php artisan durable:nexus-worker` draine les opérations que le cluster route vers cette
application.

### Trois réglages rejetés {#trois-réglages-refusés-plutôt-que-tolérés}

| Réglage | Rejeté | Raison |
|---|---|---|
| `lock.store: null` | toujours | il accorde tous les verrous, dans tous les déploiements |
| `lock.store: array` | sous `illuminate` | une reprise tourne dans un worker séparé de celui qui l'a dispatchée, donc deux verrous `array` ne se voient jamais : quinze sections critiques chevauchées sur vingt, mesurées |
| la connexion de file `sync` | sous `illuminate` | elle exécute les jobs sur place : une reprise qui en dispatche une autre récurse jusqu'à épuiser la pile |

`array` reste accepté sous `memory`. C'est le cache de test par défaut de Laravel, et un test n'a
besoin d'exclusion mutuelle qu'à l'intérieur d'un seul processus.

### Deux comportements qui ressemblent à des bugs {#deux-choses-qui-ressemblent-à-des-bugs-et-nen-sont-pas}

**Le driver `sqlite` ne peut pas héberger plus d'un worker.** Avec quatre workers qui dépilent la
table `jobs`, la file renvoie `SQLSTATE[HY000]: General error: 5 database is locked`, et trois sur
quatre meurent à leur premier job, WAL activé et `busy_timeout` à 60 s. Passez à MySQL, PostgreSQL
ou Redis pour la file dès que vous lancez un second worker.

**Le job d'un worker tué reste réservé jusqu'à `retry_after`**, 90 secondes par défaut. Un worker
lancé avec `--stop-when-empty` dans cette fenêtre trouve une file vide et sort **sans rien faire**,
ce qui ressemble trait pour trait à une reprise en échec. La reprise n'a pas encore reçu
le job. Un worker supervisé survit à la fenêtre, prend le job, et l'exécution se termine.

### Pas dans ce paquet

**Temporal est bien pris en charge.** `backend: 'temporal'` place le journal et le catalogue
d'exécutions dans le cluster, et deux workers drainent ce que la file de l'application ne peut pas
porter : `php artisan durable:temporal-worker` draine les tâches de workflow, et
`php artisan durable:temporal-worker --role=activity` les tâches d'activité.

`gplanchat/durable-bridge-temporal` est seulement **suggéré**. Il installe huit paquets, dont cinq
composants Symfony qu'une application Laravel ne charge jamais, pour quelque 36 Mo. Une application
qui ne choisit pas ce backend ne les installe jamais, et celle qui le choisit reçoit une erreur qui
nomme le paquet à installer. Scinder le pont, dont la partie couplée à Symfony fait huit fichiers
sur 774, retirerait ce poids ; c'est une modification distincte.

**Aucun tableau de bord.** [`gplanchat/durable-filament`](#gplanchatdurable-filament--le-tableau-de-bord-filament)
exige ce paquet, et ce paquet n'exige, ne suggère ni ne détecte jamais Filament.

---

## `gplanchat/durable-plugin`, le tableau de bord Sylius {#gplanchatdurable-plugin--le-tableau-de-bord-sylius}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-plugin
```

Le plugin affiche le [tableau de bord](../dashboard/) dans l'administration Sylius : une entrée
dans le menu d'administration, la liste des exécutions sur des cartes Tabler avec pagination par
curseur, et le détail à côté. Les panneaux, le regroupement et les libellés viennent de
`gplanchat/durable` lui-même : une exécution se lit donc de la même façon ici et sur l'écran
Magento. Ce paquet fournit l'habillage Sylius autour d'eux.

Les libellés affichent l'`ActivityType.name` lisible et ne retombent sur les identifiants
techniques que si aucun nom n'est disponible.

Le plugin **lit** les exécutions et n'exécute rien. Il exige `gplanchat/durable-bundle`, qui câble
le catalogue d'exécutions qu'il lit : la commande ci-dessus constitue donc toute l'installation.

> [!NOTE]
> Les données vivantes viennent du backend installé, quel qu'il soit. Le plugin n'exige aucun pont :
> `gplanchat/durable` suggère le backend, une fois, pour toutes les intégrations. Sans backend, le
> plugin s'installe quand même, la route et l'entrée de menu fonctionnent, et le tableau de bord
> affiche son état dégradé au lieu d'exécutions vivantes.

## `gplanchat/durable-filament`, le tableau de bord Filament {#gplanchatdurable-filament--le-tableau-de-bord-filament}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-filament
```

```php
// app/Providers/Filament/AdminPanelProvider.php
use Gplanchat\Durable\Filament\DurableFilamentPlugin;

return $panel
    // ...
    ->plugin(DurableFilamentPlugin::make());
```

Le plugin affiche le [tableau de bord](../dashboard/) dans un panneau Filament 3 ou 4 : une entrée
**Exécutions Durable** dans la navigation du panneau, la liste des exécutions avec pagination par
curseur et les filtres par nom et par identifiant d'exécution que le backend peut appliquer, et une
page par exécution avec son état, ce qu'elle attend, ses opérations Nexus et son historique. Il est
disponible en anglais et en français.

Le plugin **lit** les exécutions et n'exécute rien. Il exige `gplanchat/durable-laravel` et lit le
catalogue d'exécutions que ce paquet lie pour son backend : en mémoire, Illuminate ou Temporal. Rien
dans le plugin ne nomme un backend, et rien dans `gplanchat/durable-laravel` ne nomme Filament.

> [!NOTE]
> La page d'une exécution liste ses opérations Nexus quand le catalogue les rapporte, et seul celui
> de Temporal les rapporte : un journal ne peut pas contenir d'opération Nexus, donc en mémoire et
> sur Illuminate la section n'apparaît jamais.

## `gplanchat/durable-magento`, l'intégration Magento {#gplanchatdurable-magento--lintégration-magento}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-magento
```

Un module Magento 2.4 / Mage-OS, listé comme `Gplanchat_DurableModule` dans
`bin/magento module:status`. Il déclare les classes de workflow et d'activité au moteur, assemble le
moteur pour un processus Magento, livre les workers en commandes `bin/magento`, et ajoute un écran
d'administration en lecture seule sous **System > Durable processes > Process history**.

L'écran utilise l'habillage de Magento : une grille standard (pagination, signets, choix des
colonnes, export, et un filtre d'état multi-select dont les options viennent de l'énumération des
états elle-même), avec l'état du backend et les compteurs par issue au-dessus. Le contenu de l'écran
ne vient ni de Magento ni de ce paquet ; voir [le tableau de bord](../dashboard/), que chaque hôte
affiche dans son propre habillage.

Le conteneur de Magento n'a pas d'équivalent de l'autoconfiguration par tag de Symfony : vous
déclarez donc les classes explicitement, dans deux tableaux de `di.xml` :

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

Vous ne déclarez pas le *contrat*. La fabrique lit les interfaces de chaque gestionnaire et garde
celles qui portent `#[AsActivityMethod]`, ce qui fait une déclaration de moins à écrire de travers
et laisse les noms d'activité à ceux des attributs.

Deux autres arguments de la même fabrique bornent une exécution, et `di.xml` est le seul endroit où
les régler :

```xml
<argument name="maxActivityRetries" xsi:type="number">3</argument>
<argument name="budgetSeconds" xsi:type="number">30</argument>
```

- `maxActivityRetries` est le plafond de tentatives des activités que `MagentoRuntime::run()`
  exécute dans le processus appelant quand aucun DSN n'est configuré, l'équivalent du
  [`max_activity_retries`](../configuration/#max_activity_retries) du bundle Symfony. La valeur par
  défaut, `0`, ne fixe aucun plafond. Les workers Temporal ne le lisent jamais : là, le cluster
  relance d'après la `RetryLimit` propre à l'activité.
- `budgetSeconds` borne `MagentoRuntime::run()`. Sans DSN, l'appel mène un workflow à son terme dans
  le processus appelant ; avec un DSN, il attend aussi longtemps le résultat du cluster. Au-delà du
  budget, l'appel lève `WorkflowStuckException` au lieu d'attendre encore. La valeur par défaut est
  `10`. Dans le processus, le budget existe à cause du plafond de tentatives : sans plafond, une
  activité qui échoue sans cesse occuperait ce processus pour toujours. Les workers et
  `workflowClient()` ne lisent ni l'un ni l'autre.

**Magento prend en charge deux backends, et Composer l'impose.** Magento atteint la mémoire et
Temporal, et le module déclare un `conflict` sur les deux ponts SQL, car
`Magento\Framework\App\ResourceConnection` n'est ni une connexion Doctrine DBAL ni celle
d'Illuminate. Un DSN dans `app/etc/env.php` choisit le backend ; aucun autre réglage ne le fait :

```php
'durable' => [
    'temporal' => ['dsn' => 'temporal://temporal:7233?namespace=default&tls=0'],
],
```

Sans ce DSN, le journal vit dans le processus qui l'écrit et disparaît quand ce processus se
termine. C'est acceptable pour une commande en ligne, inadapté à tout le reste.

`MagentoRuntime::run()` suit le même choix. Sans DSN, il exécute le workflow dans le processus
appelant. Avec un DSN, il démarre le workflow sur le cluster et attend son résultat, que produisent
les workers ci-dessous. L'attente dure environ `budgetSeconds` et se termine par
`WorkflowStuckException`.

Un workflow qui échoue, expire ou est terminé arrive autrement chez l'appelant avec un DSN : sous la
forme d'une `\RuntimeException` simple, dont le message commence par `Workflow "<execution id>"`,
sans exception précédente. Un workflow qui attend un signal attend tout le budget au lieu d'échouer
aussitôt.

Le résultat revient décodé du JSON : un objet que le workflow renvoie arrive sous forme de tableau.

Pour démarrer un workflow sans attendre, depuis une requête web par exemple, appelez
`workflowClient()->startAsync()`.

**Les workers sont des commandes `bin/magento`**, pas des consommateurs de file. Supervisez-les
comme n'importe quel processus long :

```bash
bin/magento durable:worker --role=journal   --time-limit=3600
bin/magento durable:worker --role=activity  --time-limit=3600
```

Chaque processus sert un rôle sur une file. Les deux rôles utilisent deux files Temporal distinctes,
et vous réglez leur parallélisme séparément. Rien ne passe par le `MessageQueue` de Magento : sur
Temporal, une activité est une commande Temporal et une reprise une tâche de workflow, donc un topic
Magento ne ferait qu'ajouter une seconde file à superviser.

**Un worker absent se manifeste différemment selon son rôle.** Sans `--role=journal`, rien
n'avance : les exécutions démarrent, leur historique se remplit, et aucun processus ne répond à
leurs tâches de workflow. Sans `--role=activity`, l'exécution semble fonctionner, ce qui rend le
problème plus difficile à voir : elle avance **jusqu'à sa première activité** et s'y arrête, la
commande débitée et le stock intact, et c'est le client qui vous l'apprend. Tourner sans le worker
d'activité remet en place la panne que cette intégration existe pour supprimer.

Les bornes `--time-limit` et `--max-tasks` servent au superviseur : elles terminent le processus
pour que le superviseur puisse le relancer. Les reprises relèvent du cluster, qui planifie les
tentatives d'une activité qu'un worker écoute ou non. Une exécution dont l'activité « a échoué après
3 tentatives » en quelques secondes signale un worker absent, pas un code qui a échoué trois fois.

> [!WARNING]
> **Les réglages de file de Magento ne s'appliquent pas à Durable.** `retry_inprogress_after`, les
> tâches cron `messagequeue_*` et `queue_lock` ne portent rien de Durable, puisque rien de Durable
> ne passe par `MessageQueue`. Réglez-les pour vos propres consommateurs.

> [!NOTE]
> Démarrez les exécutions **sur le cluster**, hors de la requête qui les déclenche. Un observateur
> sur `sales_order_place_after` qui appelle `RuntimeFactory::workflowClient()->startAsync()` confie
> l'exécution à Temporal et rend la main. `workflowClient()` exige le cluster, car `startAsync()`
> n'existe que sur Temporal. Une exécution démarrée dans la requête s'arrêterait avec elle, ce qui
> est précisément la panne que cette intégration existe pour supprimer.

---

## Qu'est-ce que j'installe ?

Chaque commande ci-dessous est celle que le sélecteur de la [page d'accueil](/fr/) vous donne,
écrite en toutes lettres.

Le sélecteur lit son état dans l'URL : un lien peut donc ouvrir la page avec une situation déjà
choisie, par exemple dans un ticket, un README ou une réponse de support :

```
https://durable.rocks/fr/?fw=magento&be=temporal#install
```

`fw` est le framework (`none`, `symfony`, `laravel`, `sylius`, `apiplatform`, `magento`), `be`
l'endroit où vit l'état (`memory`, `temporal`, `dbal`, `illuminate`), et `dist` la base sous une
distribution (`none`, `symfony`, `laravel`). Chaque axe est facultatif. Le sélecteur ignore une
valeur qu'il ne peut pas appliquer (un framework qui n'est pas publié, un backend que l'appariement
interdit) au lieu de la forcer : un vieux lien retombe sur le choix par défaut au lieu d'afficher
une combinaison qui n'existe pas. Choisir dans la page réécrit la barre d'adresse : le lien à
partager est donc celui que vous avez déjà dans la barre d'adresse.

Chaque commande du tableau suppose que le projet accepte d'abord la ligne bêta :

```bash
composer config minimum-stability beta
composer config prefer-stable true
```

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
| Magento, un cluster Temporal | `composer require gplanchat/durable-magento gplanchat/durable-bridge-temporal` |

Chaque ligne ne nomme que l'intégration : le bundle tire la bibliothèque, et le plugin tire le
bundle. Sans framework, vous nommez la bibliothèque vous-même, et vous câblez aussi les workers
vous-même.

La ligne Laravel nomme la bibliothèque plutôt qu'une intégration, et c'est désormais un *choix*, non
plus un manque. `gplanchat/durable-laravel` existe : un service provider qui lie les quatre ports
de stockage, des workflows déclarés dans `config/durable.php`, et le travail sur la file que
l'application draine déjà. Tant qu'il n'est pas tagué, le pont s'installe seul et vous le câblez
vous-même ; la section ci-dessus décrit ce que l'intégration fait à votre place.

---

## Le même comportement sur tous les backends {#un-seul-code-un-seul-comportement}

Tous les backends font tourner le **même pilote à fibres** et le **même chemin d'exécution des
activités**. Un workflow que vous avez testé en mémoire se comporte de la même façon contre DBAL ou
contre Temporal, y compris pour le décompte des réessais, la classification des échecs,
l'annulation et la compensation.

Quand une capacité n'a pas d'équivalent sur un backend, ce backend **échoue avec un message
explicite**. [Backends](../backends/#capability-matrix) liste les différences.

---

## Monorepo et publications

Durable se développe dans un seul dépôt, `gplanchat/durable-dev`. Une scission publie chaque paquet
dans son propre dépôt en lecture seule, si bien qu'un `composer require` tire un petit paquet plutôt
que tout l'arbre.
