---
title: Premiers pas
weight: 10
---

# Premiers pas

## Ce qu'il vous faut

- **PHP 8.2+**
- **Composer**
- Pour les tests : aucune infrastructure supplémentaire, le backend **en mémoire** tourne entièrement dans un seul processus PHP.
- Pour le développement local et la production **sans cluster** : une seule base SQL, par le backend **DBAL** sous Symfony ou le backend **Illuminate** sous Laravel. Aucune extension à compiler.
- Avec un cluster, pour la production **à l'échelle** ou des tests d'intégration réalistes : un cluster **Temporal** (image Docker disponible) et l'extension PHP **`ext-grpc`**. Dans une image de conteneur, copiez-la depuis une [image préconstruite](../container-images/) plutôt que de la compiler.

Les quatre backends font tourner le même code de workflow ; [Backends](../backends/) compare ce que
chacun sait offrir.

---

## Installation

**Cette page déroule l'intégration Symfony.** Durable a trois intégrations d'hôte, et se tromper de
paquet est l'erreur à éviter dès la première ligne, car chacune a son câblage, son fichier de
configuration et son worker :

| Votre application | À installer | À lire plutôt |
|---|---|---|
| **Symfony** (Sylius compris) | `gplanchat/durable-bundle` | cette page |
| **Laravel** | `gplanchat/durable-laravel` | [Paquets](../packages/#gplanchatdurable-laravel--lintégration-laravel) |
| **Magento 2.4 / Mage-OS** | `gplanchat/durable-magento` | [Paquets](../packages/#gplanchatdurable-magento--lintégration-magento) |
| **Sans framework** | `gplanchat/durable` | [Paquets](../packages/#gplanchatdurable--la-bibliothèque) |

Les concepts, l'API de workflow et l'API d'activité sont identiques sur les quatre ; seul le câblage
ci-dessous est celui de Symfony.

### La bibliothèque seule (sans framework)

```bash
composer require gplanchat/durable
```

### L'intégration Symfony

```bash
composer config extra.symfony.allow-contrib true
composer require gplanchat/durable-bundle
```

**La première ligne compte.** La recette Flex du bundle vit dans `symfony/recipes-contrib`, et Flex
demande avant d'exécuter une recette contrib : la réponse par défaut, et la seule sous
`--no-interaction`, est non. L'installation affiche alors `IGNORING gplanchat/durable-bundle`,
`config/bundles.php` ne nomme jamais le bundle, et la configuration ci-dessous échoue avec *There is
no extension able to load the configuration for "durable"*. Autorisez les recettes contrib comme
ci-dessus, répondez `y` à la question, ou ajoutez vous-même la ligne à `config/bundles.php` :

```php
return [
    // ...
    Gplanchat\Durable\Bundle\DurableBundle::class => ['all' => true],
];
```

La recette écrit aussi un `config/packages/durable.yaml` et deux lignes `MESSENGER_DURABLE_*_DSN`
dans `.env`. Les fichiers ci-dessous **remplacent** ce `durable.yaml` : celui de la recette nomme
les magasins avec `event_store.type` et `workflow_metadata.type`, dépréciés depuis que `backend`
les remplace. Une fois remplacé, plus rien ne lit les deux lignes de `.env` ; supprimez-les.

---

## Configuration Symfony minimale

### `config/packages/durable.yaml`

Par défaut, le bundle utilise le backend **en mémoire**. Il convient aux tests, et seulement aux
tests : il ne garde rien d'un processus à l'autre. [Dans quel profil êtes-vous ?](#dans-quel-profil-êtes-vous-)
dit sur quoi tourne le développement local.

```yaml
durable:
    backend: in_memory       # 'dbal' ou 'temporal' dans les profils plus bas
    activity_transport:
        type: messenger
        transport_name: durable_activities
    child_workflow:
        async_messenger: true
    activity_contracts:
        cache: cache.app
        contracts:
            - App\Workflow\Activity\GreetingActivities   # listez ici vos interfaces d'activité
```

Activez Temporal pour un environnement en nommant le backend et en lui donnant le DSN. Le DSN est
lu à la compilation du conteneur : un environnement qui a ces lignes est un environnement Temporal,
même avec un `DURABLE_DSN` vide.

```yaml
when@dev:
    durable:
        backend: temporal
        temporal:
            dsn: '%env(DURABLE_DSN)%'
```

### `config/packages/messenger.yaml`

Durable s'appuie sur **Symfony Messenger** pour router ses messages internes. Sous Temporal, le
bundle enregistre lui-même les workers `durable_workflows` et `durable_activities` ; les deux
transports Messenger du même nom, et leur routage, n'appartiennent qu'aux environnements sans
cluster — ici `test`. Un environnement qui a un DSN et les déclare refuse de compiler.

```yaml
framework:
    messenger:
        transports:
            sync: 'sync://'
        routing:
            Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage: sync
            Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage: sync

when@test:
    framework:
        messenger:
            transports:
                durable_workflows:  'in-memory://'
                durable_activities: 'in-memory://'
            routing:
                Gplanchat\Durable\Transport\ResumeWorkflowMessage:     durable_workflows
                Gplanchat\Durable\Transport\ActivityMessage:           durable_activities
                Gplanchat\Durable\Transport\FireWorkflowTimersMessage: durable_workflows
```

Pour Temporal (`dev` / `prod`) :

```yaml
# .env.dev (ou .env.local)
DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
```

---

## Déclarer workflows et activités

### Marquer les workflows

Rien à écrire. Une classe portant `#[AsWorkflow]` est enregistrée dès qu'elle est un service — ce
qu'avec l'`autoconfigure: true` par défaut d'une application Symfony elle est déjà.

Les versions précédentes demandaient de marquer le dossier à la main :

```yaml
# config/services.yaml — désormais inutile
App\Workflow\:
    resource: '../src/Workflow/'
    exclude: '../src/Workflow/Activity/'
    tags: [durable.workflow]
```

La balise fonctionne toujours : une application qui l'écrit continue de marcher, elle fait
simplement double emploi. Si vous la gardez, l'`exclude` compte encore : la balise ne filtre rien,
chaque service qu'elle attrape est passé au registre des workflows, qui exige exactement un
`#[AsWorkflowMethod]` et lève sinon.

### Déclarer les implémentations d'activité

Rien à écrire. Une classe portant `#[AsActivityHandler]` est ramassée par l'autoconfiguration du bundle dès qu'elle est un service, ce qu'avec l'`autoconfigure: true` par défaut d'une application Symfony elle est déjà.

---

## Un premier workflow

### 1. Définir un contrat d'activité {#1--définir-un-contrat-dactivité}

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;

// Optionnel : préfixe le nom des activités déclarées en dessous.
#[AsActivity(name: 'greeting-activities')]
interface GreetingActivities
{
    #[AsActivityMethod(name: 'greet')]
    public function greet(string $name): string;
}
```

### 2. Implémenter l'activité {#2--implémenter-lactivité}

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;

// C'est cet attribut qui enregistre la classe ; le bundle l'autoconfigure.
#[AsActivityHandler(contract: GreetingActivities::class)]
final class GreetingActivitiesHandler implements GreetingActivities
{
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}
```

### 3. Définir le workflow {#3--définir-le-workflow}

```php
<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Workflow\Activity\GreetingActivities;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'greet')]
final class GreetWorkflow
{
    /** @param ActivityStub<GreetingActivities> $greeting */
    #[AsWorkflowMethod]
    public function run(
        string $name,
        #[Activities(GreetingActivities::class)]
        ActivityStub $greeting,
        WorkflowEnvironment $env,
    ): string {
        return $env->await($greeting->greet($name));
    }
}
```

`$name` vient de l'entrée avec laquelle le workflow démarre. `$greeting` et `$env`, non : Durable
les fournit, comme Symfony fournit ses services à un contrôleur. Voir
[Les arguments que fournit Durable](../workflows/#arguments-durable-supplies).

### 4. Le déclencher depuis un contrôleur ou un service {#4--le-déclencher-depuis-un-contrôleur-ou-un-service}

`WorkflowResumeDispatcher::dispatchNewWorkflowRun()` est **la** façon de démarrer une exécution, et
la seule qui marche sur tous les backends : en mémoire, DBAL et Temporal. Sur Temporal, elle appelle
`startAsync()` du client à votre place. N'appelez `startAsync()` vous-même que pour ses options de
démarrage (délais, attributs de recherche, cron) : il appartient au client Temporal et n'existe
nulle part ailleurs.

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;

final class GreetController
{
    public function __construct(
        private readonly WorkflowResumeDispatcher $dispatcher,
    ) {}

    public function __invoke(string $name): JsonResponse
    {
        $executionId = 'greet-'.uniqid();
        $this->dispatcher->dispatchNewWorkflowRun($executionId, 'greet', ['name' => $name]);

        // 202 : le run est en file, pas terminé. Répondre 200 ici est la première chose qui
        // fait attendre un résultat qu'aucun consommateur n'a encore produit.
        return new JsonResponse(['executionId' => $executionId], JsonResponse::HTTP_ACCEPTED);
    }
}
```

### 5. Faire tourner un consommateur, sinon rien n'arrive

`dispatchNewWorkflowRun()` rend `void` et fait exactement ce que son nom dit : il *envoie*. Le
workflow s'exécute quand quelque chose consomme les transports configurés plus haut. D'ici là
l'exécution attend en file, et un tableau de bord la dira `RUNNING`, ce qui est vrai et inutile : ça
veut dire *pas terminée*, pas *quelqu'un s'en occupe*.

```bash
php bin/console durable:worker
```

Elle lit les noms des transports dans votre propre configuration : là où `messenger.yaml` route
`ResumeWorkflowMessage` et `FireWorkflowTimersMessage`, et l'`activity_transport` de
`durable.yaml`. Elle affiche ce qu'elle consomme (`Consuming durable_workflows, durable_activities.`)
et confie le reste à `messenger:consume`, à qui elle transmet `--limit`, `--time-limit`,
`--memory-limit`, `--failure-limit`, `--sleep` et `--no-reset`. Quand les reprises ne sont routées
nulle part, ou seulement vers `sync`, elle refuse de démarrer et le dit, au lieu d'attendre devant une
file vide.

`messenger:consume durable_workflows durable_activities` fonctionne toujours : ces deux noms sont les
transports que **vous** avez déclarés dans `messenger.yaml`. Les signaux et les mises à jour n'y
figurent pas : le guide les route vers `sync`. Si vous les routez vers un transport asynchrone à vous,
consommez ce transport vous-même : `durable:worker` ne le cherche pas.

Ces deux commandes relèvent du profil **à plusieurs processus** décrit plus bas : un worker dans son
propre processus, sur de vrais transports. Le profil `when@test` ci-dessus met les deux transports en
`in-memory://`, et un worker n'y draine rien : un transport en mémoire ne contient que ce que son
propre processus a envoyé, et Messenger réinitialise les services après chaque message traité, ce qui
vide la file en mémoire. L'activité que le workflow vient de mettre en file disparaît, et l'exécution
reste pour de bon sur `ActivityScheduled`. Dans ce profil, drainez l'exécution dans le test qui l'a
envoyée (`DurableBundleTestTrait`, voir [Tester des workflows](../testing/)), ou, dans ce même
processus, consommez avec `--no-reset`. Sans lui, les deux commandes refusent de démarrer sur un
transport Durable en mémoire, et disent par où sortir.

Pour voir ce que le moteur retient d'une exécution :

```bash
php bin/console durable:execution:diagnose greet-abc123
```

Elle affiche les métadonnées de l'exécution, ses liens parent et enfants, et les premiers
événements de son journal avec leurs charges utiles : l'entrée du workflow, les arguments et le
résultat de chaque activité. Les valeurs rangées sous des clés comme `password`, `token`, `secret`,
`authorization`, `card` ou `api_key` sont masquées et les longues chaînes tronquées ; `--raw` les affiche
telles qu'elles sont stockées. Le masquage se fie au nom de la clé : des données personnelles
rangées sous d'autres clés restent visibles, attention à l'endroit où vous collez la sortie. Le
panneau du profileur web masque de la même façon.

#### Dans quel profil êtes-vous ?

Trois configurations fonctionnent, une par étape. Les mélanger est le faux pas habituel, et il
échoue en silence.

**Les tests : un seul processus, en mémoire.** Transports `in-memory://` et magasins en mémoire. Envoi, reprise
et activité se passent dans un même processus PHP, donc un test envoie et draine d'un seul geste, avec
`DurableBundleTestTrait` ou avec `durable:worker --no-reset` dans ce processus ; les commandes de
consommation de l'étape 5 sont pour le profil suivant. Un
transport en mémoire **ne survit pas à son processus** : y envoyer depuis une requête web pour
consommer dans un worker séparé ne peut pas marcher, et le rejeu non plus : le journal dont le
worker aurait besoin vit dans la mémoire du processus web.

**Le développement local, et la production sans cluster : plusieurs processus, sur DBAL.** De vrais transports **et** un magasin
durable, sinon le worker prend une entrée nommant un workflow dont il ne voit pas le journal.

Ce profil demande des paquets que la prise en main ci-dessus n'installe pas : le journal DBAL,
DoctrineBundle pour le service `doctrine.dbal.default_connection` qu'il nomme, et le transport
Doctrine de Messenger derrière les files `doctrine://` plus bas. La recette de DoctrineBundle
configure aussi l'ORM, d'où `doctrine/orm` ; ou retirez la section `orm:` de
`config/packages/doctrine.yaml` si vous n'utilisez pas l'ORM.

```bash
composer require gplanchat/durable-bridge-dbal doctrine/doctrine-bundle doctrine/orm symfony/doctrine-messenger
```


```yaml
durable:
    backend: dbal
    dbal:
        connection: doctrine.dbal.default_connection
```

Les deux files quittent `when@test:` pour cet environnement, sur Doctrine, avec le même routage :

```yaml
framework:
    messenger:
        transports:
            durable_workflows:  'doctrine://default?queue_name=durable_workflows'
            durable_activities: 'doctrine://default?queue_name=durable_activities'
```

**Avec un cluster Temporal : le DSN, et rien d'autre.** Un environnement dont
`durable.temporal.dsn` est renseigné tourne sur Temporal. Le cluster tient le journal et les files,
et les [workers ci-dessous](#démarrer-les-workers-temporal-production--mode-dev) l'interrogent. Le
bloc `when@dev` plus haut met `dev` dans ce profil ; retirez-le pour développer sur DBAL.

La règle derrière les trois profils : **une exécution survit exactement à ce à quoi survivent son
journal et sa file.** Routez `ResumeWorkflowMessage` ou `ActivityMessage` vers un transport qu'un
worker séparé ne peut pas lire, et le workflow rejoue dans la requête web qui l'a démarré puis meurt
avec le processus, précisément la panne que l'exécution durable existe pour supprimer.

---

## Démarrer les workers Temporal (production / mode dev)

Quand `DURABLE_DSN` pointe vers un serveur Temporal, lancez les workers enregistrés par le bundle dans
des processus séparés. **Ce sont les commandes Symfony** ; les autres hôtes interrogent le même cluster
avec les leurs : `php artisan durable:temporal-worker` et `--role=activity` sous Laravel,
`bin/magento durable:worker --role=journal` et `--role=activity` sous Magento :

```bash
# Worker des tâches de workflow (interroge Temporal pour les tâches de workflow)
php bin/console durable:worker --role=workflow

# Worker d'activités (interroge Temporal pour les tâches d'activité)
php bin/console durable:worker --role=activity
```

Une application qui [sert une opération Nexus](../nexus/) en lance un troisième, `--role=nexus`.
Sous le capot, ce sont les récepteurs `durable_workflows`, `durable_activities` et `durable_nexus`,
et `messenger:consume` accepte aussi ces noms.

Sur Temporal, lancez **un processus par rôle**. Chaque récepteur interroge le cluster en attente
longue, et un worker interroge ses récepteurs à tour de rôle : dans un seul processus, une tâche de
workflow peut attendre qu'une attente d'activité inoccupée expire avant d'être prise. Pour la même
raison, `--limit` et `--failure-limit` n'arrêtent jamais un worker Temporal : ses récepteurs ne
remettent aucun message à Messenger. `--time-limit` et `--memory-limit` l'arrêtent, une fois
l'attente en cours terminée.

En développement local avec `symfony serve`, ajoutez ceci à `.symfony.local.yaml` :

```yaml
workers:
    workflows:
        cmd: ['symfony', 'console', 'durable:worker', '--role=workflow', '--time-limit=3600']
    activities:
        cmd: ['symfony', 'console', 'durable:worker', '--role=activity', '--time-limit=3600']
```

---

## Et ensuite

- [Concepts](../concepts/) couvre le modèle de rejeu, les backends et l'historique d'événements, en français courant.
- [Écrire un workflow](../workflows/) couvre l'API complète : signaux, requêtes, mises à jour, workflows enfants, minuteurs.
- [Écrire des activités](../activities/) couvre `ActivityOptions`, réessais, délais et injection de dépendances.
- [Tester des workflows](../testing/) couvre `DurableTestCase`, `ActivitySpy` et `DurableBundleTestTrait`.
- [Référence de configuration](../configuration/) explique chaque clé de `durable.yaml`.
- [Backends](../backends/) couvre la mémoire, DBAL, Illuminate et Temporal : quand choisir lequel, et la mise en place Docker Compose.
