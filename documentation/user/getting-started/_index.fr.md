---
title: Premiers pas
weight: 10
---

# Premiers pas

## Ce qu'il vous faut

- **PHP 8.2+**
- **Composer**
- Pour les tests et le développement local sans Temporal : aucune infrastructure supplémentaire — le backend **en mémoire** tourne entièrement dans PHP.
- Pour la production ou des tests d'intégration réalistes : un cluster **Temporal** (image Docker disponible) et l'extension PHP **`ext-grpc`** — dans une image de conteneur, copiez-la depuis une [image préconstruite](../container-images/) plutôt que de la compiler.

---

## Installation

### La bibliothèque seule (sans framework)

```bash
composer require gplanchat/durable
```

### L'intégration Symfony

```bash
composer require gplanchat/durable-bundle
```

Activez le bundle dans `config/bundles.php` (Symfony Flex l'enregistre en général tout seul) :

```php
return [
    // ...
    Gplanchat\Durable\Bundle\DurableBundle::class => ['all' => true],
];
```

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
            - App\Workflow\Activity\OrderActivities   # listez ici vos interfaces d'activité
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
            Gplanchat\Durable\Transport\FireWorkflowTimersMessage:    sync
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
    tags: [durable.workflow]
```

### Déclarer les implémentations d'activité

Les classes d'implémentation d'activité sont des services Symfony ordinaires (l'autowiring s'applique). Si vous posez `#[AsActivityHandler]` sur la classe, le bundle les ramasse tout seul dès que le service est marqué.

---

## Un premier workflow

### 1 — Définir un contrat d'activité

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

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

use Gplanchat\Durable\Attribute\AsActivity;

#[AsActivity(name: 'greeting-activities')]
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

        return new JsonResponse(['executionId' => $executionId]);
    }
}
```

---

## Démarrer les workers Temporal (production / mode dev)

Quand `DURABLE_DSN` pointe vers un serveur Temporal, lancez les consommateurs Messenger dans des processus séparés :

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
- [Backends](../backends/) — en mémoire ou Temporal : quand choisir lequel, et la mise en place Docker Compose.
