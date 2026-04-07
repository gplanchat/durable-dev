---
title: Getting started
weight: 10
---

# Getting started

## What you need

- **PHP 8.2+**
- **Composer**
- For tests and local development without Temporal: no additional infrastructure — the **In-Memory** backend runs fully inside PHP.
- For production or realistic integration tests: a **Temporal** cluster (Docker image available) and the **`ext-grpc`** PHP extension.

---

## Install

### Core component only (framework-agnostic)

```bash
composer require gplanchat/durable
```

### Symfony integration

```bash
composer require gplanchat/durable-bundle
```

Enable the bundle in `config/bundles.php` (usually auto-registered via Symfony Flex):

```php
return [
    // ...
    Gplanchat\Durable\Bundle\DurableBundle::class => ['all' => true],
];
```

---

## Minimal Symfony configuration

### `config/packages/durable.yaml`

The bundle defaults to the **In-Memory** backend, which is correct for tests and development without a Temporal server:

```yaml
durable:
    event_store:
        type: in_memory
    temporal:
        dsn: null            # set via env var for Temporal
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
            - App\Workflow\Activity\OrderActivities   # list your activity interfaces here
```

Switch to Temporal at runtime by setting `DURABLE_DSN` in your environment:

```yaml
when@dev:
    durable:
        temporal:
            dsn: '%env(DURABLE_DSN)%'
```

### `config/packages/messenger.yaml`

Durable uses **Symfony Messenger** to route internal messages. Add the transports and routing:

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

For tests and local dev, set both DSNs to `in-memory://`:

```yaml
# .env.test
MESSENGER_DURABLE_WORKFLOW_DSN=in-memory://
MESSENGER_DURABLE_ACTIVITY_DSN=in-memory://
DURABLE_DSN=
```

For Temporal (`dev`/`prod`):

```yaml
# .env.dev (or .env.local)
DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
```

When Temporal is active, add the worker transports (`when@dev:` / `when@prod:`):

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

## Register workflows and activities

### Tag workflows

Any class annotated with `#[Workflow]` in your workflow namespace is auto-registered when you tag the folder:

```yaml
# config/services.yaml
App\Workflow\:
    resource: '../src/Workflow/'
    tags: [durable.workflow]
```

### Register activity implementations

Activity implementation classes are registered as normal Symfony services (autowiring applies). If you use `#[AsDurableActivity]` on the class, the bundle picks them up automatically when the service is tagged.

---

## First workflow

### 1 — Define an activity contract

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface GreetingActivities
{
    #[ActivityMethod(name: 'greet')]
    public function greet(string $name): string;
}
```

### 2 — Implement the activity

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Activity;

use Gplanchat\Durable\Attribute\Activity;

#[Activity(name: 'greeting-activities')]
final class GreetingActivitiesHandler implements GreetingActivities
{
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}
```

### 3 — Define the workflow

```php
<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Workflow\Activity\GreetingActivities;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow(name: 'greet')]
final class GreetWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[WorkflowMethod]
    public function run(string $name): string
    {
        $activities = $this->environment->activityStub(GreetingActivities::class);

        return $this->environment->await($activities->greet($name));
    }
}
```

### 4 — Dispatch from a controller or service

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

## Start Temporal workers (production / dev mode)

When `DURABLE_DSN` points to a Temporal server, start the Messenger consumers in separate processes:

```bash
# Workflow task worker (polls Temporal for workflow tasks)
php bin/console messenger:consume durable_temporal_journal

# Activity worker (polls Temporal for activity tasks)
php bin/console messenger:consume durable_temporal_activity
```

For local development with `symfony serve`, add to `.symfony.local.yaml`:

```yaml
workers:
    journal:
        cmd: ['symfony', 'console', 'messenger:consume', 'durable_temporal_journal', '--time-limit=3600']
    activity:
        cmd: ['symfony', 'console', 'messenger:consume', 'durable_temporal_activity', '--time-limit=3600']
```

---

## Next steps

- [Concepts](../concepts/) — replay model, backends, event history in plain language.
- [Creating a workflow](../workflows/) — full workflow API: signals, queries, updates, child workflows, timers.
- [Creating activities](../activities/) — `ActivityOptions`, retries, timeouts, dependency injection.
- [Testing workflows](../testing/) — `DurableTestCase`, `ActivitySpy`, `DurableBundleTestTrait`.
- [Configuration reference](../configuration/) — every `durable.yaml` key explained.
- [Backends](../backends/) — In-Memory vs Temporal: when to use each, Docker Compose setup.
