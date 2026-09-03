---
title: Premiers pas
weight: 10
---

# Premiers pas

## Ce qu'il vous faut

- **PHP 8.2+**
- **Composer**
- Pour les tests et le développement local : aucune infrastructure supplémentaire — le backend **en mémoire** tourne entièrement dans PHP.
- Pour la production **sans cluster** : une seule base SQL, par le backend **DBAL** sous Symfony ou le backend **Illuminate** sous Laravel. Aucune extension à compiler.
- Pour la production **à l'échelle**, ou des tests d'intégration réalistes : un cluster **Temporal** (image Docker disponible) et l'extension PHP **`ext-grpc`** — dans une image de conteneur, copiez-la depuis une [image préconstruite](../container-images/) plutôt que de la compiler.

Les quatre backends font tourner le même code de workflow ; [Backends](../backends/) compare ce que
chacun sait offrir.

---

## Installation

**Cette page déroule l'intégration Symfony.** Durable a trois intégrations d'hôte, et se tromper de
paquet est l'erreur à éviter dès la première ligne — chacune a son câblage, son fichier de
configuration et son worker :

| Votre application | À installer | À lire plutôt |
|---|---|---|
| **Symfony** (Sylius compris) | `gplanchat/durable-bundle` | cette page |
| **Laravel** | `gplanchat/durable-laravel` | [Paquets](../packages/#gplanchatdurable-laravel--lintégration-laravel) |
| **Magento 2.4 / Mage-OS** | `gplanchat/durable-magento` | [Paquets](../packages/#gplanchatdurable-magento--lintégration-magento) |
| **Sans framework** | `gplanchat/durable` | [Paquets](../packages/#gplanchatdurable--la-bibliothèque) |

Les concepts, l'API de workflow et l'API d'activité sont identiques sur les quatre — seul le câblage
ci-dessous est celui de Symfony.

### La bibliothèque seule (sans framework)

```bash
composer require gplanchat/durable
```

### L'intégration Symfony

```bash
composer require gplanchat/durable-bundle
```

Le paquet déclare `"type": "symfony-bundle"`, donc **Symfony Flex l'enregistre tout seul** — il n'y
a rien à ajouter à `config/bundles.php`. Sans Flex, ajoutez la ligne vous-même :

```php
return [
    // ...
    Gplanchat\Durable\Bundle\DurableBundle::class => ['all' => true],
];
```

C'est tout ce que Flex fait ici : la configuration ci-dessous reste à votre charge.

---

## Configuration Symfony minimale

### `config/packages/durable.yaml`

Par défaut, le bundle utilise le backend **en mémoire**, ce qui convient aux tests et au développement sans serveur Temporal :

```yaml
durable:
    event_store:
        type: in_memory
    temporal:
        dsn: null            # à définir par variable d'environnement pour Temporal
    workflow_metadata:
        type: in_memory
    activity_transport:
        type: messenger
        transport_name: durable_activities
    child_workflow:
        async_messenger: true
        parent_link_store:
            type: in_memory
    activity_contracts:
        cache: cache.app
        contracts:
            - App\Workflow\Activity\GreetingActivities   # listez ici vos interfaces d'activité
```

Basculez sur Temporal à l'exécution en définissant `DURABLE_DSN` dans votre environnement :

```yaml
when@dev:
    durable:
        temporal:
            dsn: '%env(DURABLE_DSN)%'
```

### `config/packages/messenger.yaml`

Durable s'appuie sur **Symfony Messenger** pour router ses messages internes. Ajoutez les transports et le routage :

```yaml
framework:
    messenger:
        transports:
            durable_workflows:  '%env(MESSENGER_DURABLE_WORKFLOW_DSN)%'
            durable_activities: '%env(MESSENGER_DURABLE_ACTIVITY_DSN)%'

        routing:
            Gplanchat\Durable\Transport\ResumeWorkflowMessage:        durable_workflows
            Gplanchat\Durable\Transport\ActivityMessage:              durable_activities
            Gplanchat\Durable\Transport\FireWorkflowTimersMessage:    durable_workflows
            Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage: sync
            Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage: sync
```

Pour les tests et le développement local, pointez les deux DSN sur `in-memory://` :

```yaml
# .env.test
MESSENGER_DURABLE_WORKFLOW_DSN=in-memory://
MESSENGER_DURABLE_ACTIVITY_DSN=in-memory://
DURABLE_DSN=
```

Pour Temporal (`dev` / `prod`) :

```yaml
# .env.dev (ou .env.local)
DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
```

Quand Temporal est actif, ajoutez les transports du worker (`when@dev:` / `when@prod:`) :

```yaml
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
```

---

## Déclarer workflows et activités

### Marquer les workflows

Toute classe portant `#[AsWorkflow]` dans votre espace de noms de workflows est enregistrée automatiquement dès que vous marquez le dossier :

```yaml
# config/services.yaml
App\Workflow\:
    resource: '../src/Workflow/'
    exclude: '../src/Workflow/Activity/'
    tags: [durable.workflow]
```

L'`exclude` compte. La balise ne filtre rien : chaque service qu'elle attrape est passé au registre
des workflows, qui exige exactement un `#[AsWorkflowMethod]` et lève sinon. Baliser un dossier qui
porte aussi vos gestionnaires d'activité, et le conteneur cesse de se construire sur une erreur
nommant une classe que vous n'enregistriez pas exprès. Au dossier balisé, ses seuls workflows.

### Déclarer les implémentations d'activité

Rien à écrire. Une classe portant `#[AsActivityHandler]` est ramassée par l'autoconfiguration du bundle dès qu'elle est un service — ce qu'avec l'`autoconfigure: true` par défaut d'une application Symfony elle est déjà. C'est là que les workflows ci-dessus diffèrent : eux ont encore besoin de la balise.

---

## Un premier workflow

### 1 — Définir un contrat d'activité

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

### 2 — Implémenter l'activité

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

### 3 — Définir le workflow

```php
<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Workflow\Activity\GreetingActivities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'greet')]
final class GreetWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsWorkflowMethod]
    public function run(string $name): string
    {
        $activities = $this->environment->activityStub(GreetingActivities::class);

        return $this->environment->await($activities->greet($name));
    }
}
```

### 4 — Le déclencher depuis un contrôleur ou un service

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

### 5 — Faire tourner un consommateur, sinon rien n'arrive

`dispatchNewWorkflowRun()` rend `void` et fait exactement ce que son nom dit : il *envoie*. Le
workflow s'exécute quand quelque chose consomme les transports configurés plus haut. D'ici là
l'exécution attend en file — un tableau de bord la dira `RUNNING`, ce qui est vrai et inutile : ça
veut dire *pas terminée*, pas *quelqu'un s'en occupe*.

```bash
php bin/console messenger:consume durable_workflows durable_activities
```

Ces deux noms sont les transports que **vous** avez déclarés dans `messenger.yaml`. Aucun document
ne peut vous donner cette commande sans que vous ayez écrit ce fichier d'abord — c'est ce qui fait
qu'on cherche la pièce manquante partout sauf dans sa propre configuration.

Pour voir ce que le moteur retient d'une exécution :

```bash
php bin/console durable:execution:diagnose greet-abc123
```

#### Dans quel profil êtes-vous ?

Deux configurations fonctionnent. Les mélanger est le faux pas habituel, et il échoue en silence.

**Un seul processus — les tests.** Transports `in-memory://` et magasins en mémoire. Envoi, reprise
et activité se passent dans un même processus PHP, donc un test envoie et draine d'un seul geste. Un
transport en mémoire **ne survit pas à son processus** : y envoyer depuis une requête web pour
consommer dans un worker séparé ne peut pas marcher, et le rejeu non plus — le journal dont le
worker aurait besoin vit dans la mémoire du processus web.

**Plusieurs processus — développement local et production.** De vrais transports **et** un magasin
durable, sinon le worker prend une entrée nommant un workflow dont il ne voit pas le journal.

Ce profil demande deux paquets que la prise en main ci-dessus n'installe pas — le journal DBAL, et
DoctrineBundle pour le service `doctrine.dbal.default_connection` qu'il nomme :

```bash
composer require gplanchat/durable-bridge-dbal doctrine/doctrine-bundle
```


```yaml
durable:
    event_store:
        type: dbal
    workflow_metadata:
        type: dbal
    child_workflow:
        parent_link_store:
            type: dbal
    dbal:
        connection: doctrine.dbal.default_connection
```

```dotenv
MESSENGER_DURABLE_WORKFLOW_DSN=doctrine://default
MESSENGER_DURABLE_ACTIVITY_DSN=doctrine://default
```

La règle derrière les deux profils : **une exécution survit exactement à ce à quoi survivent son
journal et sa file.** Routez `ResumeWorkflowMessage` ou `ActivityMessage` vers un transport qu'un
worker séparé ne peut pas lire, et le workflow rejoue dans la requête web qui l'a démarré puis meurt
avec le processus — précisément la panne que l'exécution durable existe pour supprimer.

---

## Démarrer les workers Temporal (production / mode dev)

Quand `DURABLE_DSN` pointe vers un serveur Temporal, lancez les consommateurs Messenger dans des
processus séparés. **Ce sont les commandes Symfony** — les autres hôtes interrogent le même cluster
avec les leurs : `php artisan durable:temporal-worker` sous Laravel,
`bin/magento durable:worker --role=journal` et `--role=activity` sous Magento :

```bash
# Worker des tâches de workflow (interroge Temporal pour les tâches de workflow)
php bin/console messenger:consume durable_temporal_journal

# Worker d'activités (interroge Temporal pour les tâches d'activité)
php bin/console messenger:consume durable_temporal_activity
```

En développement local avec `symfony serve`, ajoutez ceci à `.symfony.local.yaml` :

```yaml
workers:
    journal:
        cmd: ['symfony', 'console', 'messenger:consume', 'durable_temporal_journal', '--time-limit=3600']
    activity:
        cmd: ['symfony', 'console', 'messenger:consume', 'durable_temporal_activity', '--time-limit=3600']
```

---

## Et ensuite

- [Concepts](../concepts/) — le modèle de rejeu, les backends, l'historique d'événements, en français courant.
- [Écrire un workflow](../workflows/) — l'API complète : signaux, requêtes, mises à jour, workflows enfants, minuteurs.
- [Écrire des activités](../activities/) — `ActivityOptions`, réessais, délais, injection de dépendances.
- [Tester des workflows](../testing/) — `DurableTestCase`, `ActivitySpy`, `DurableBundleTestTrait`.
- [Référence de configuration](../configuration/) — chaque clé de `durable.yaml`, expliquée.
- [Backends](../backends/) — en mémoire, DBAL, Illuminate et Temporal : quand choisir lequel, et la mise en place Docker Compose.
