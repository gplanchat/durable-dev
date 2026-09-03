---
title: Getting started
weight: 10
---

# Getting started

## What you need

- **PHP 8.2+**
- **Composer**
- For tests and local development: no additional infrastructure — the **In-Memory** backend runs fully inside PHP.
- For production **without a cluster**: one SQL database, through the **DBAL** backend on Symfony or the **Illuminate** backend on Laravel. No extension to compile.
- For production **at scale**, or realistic integration tests: a **Temporal** cluster (Docker image available) and the **`ext-grpc`** PHP extension — in a container image, copy it from a [prebuilt image](../container-images/) rather than compiling it.

The four backends run the same workflow code; [Backends](../backends/) compares what each one can
offer.

---

## Install

**This page walks through the Symfony integration.** Durable has three host integrations, and
installing the wrong one is the mistake to avoid on the first line — each has its own wiring,
its own configuration file and its own worker:

| Your application | Install | Read instead |
|---|---|---|
| **Symfony** (incl. Sylius) | `gplanchat/durable-bundle` | this page |
| **Laravel** | `gplanchat/durable-laravel` | [Packages](../packages/#gplanchatdurable-laravel--the-laravel-integration) |
| **Magento 2.4 / Mage-OS** | `gplanchat/durable-magento` | [Packages](../packages/#gplanchatdurable-magento--the-magento-integration) |
| **No framework** | `gplanchat/durable` | [Packages](../packages/#gplanchatdurable--the-library) |

The concepts, the workflow API and the activity API are identical on all four — only the wiring
below is Symfony's.

### Core component only (framework-agnostic)

```bash
composer require gplanchat/durable
```

### Symfony integration

```bash
composer require gplanchat/durable-bundle
```

The package declares `"type": "symfony-bundle"`, so **Symfony Flex registers it on its own** — there
is nothing to add to `config/bundles.php`. Without Flex, add the line yourself:

```php
return [
    // ...
    Gplanchat\Durable\Bundle\DurableBundle::class => ['all' => true],
];
```

Registration is all Flex does here: the configuration below is still yours to write.

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
            - App\Workflow\Activity\GreetingActivities   # list your activity interfaces here
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
            Gplanchat\Durable\Transport\FireWorkflowTimersMessage:    durable_workflows
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

Any class annotated with `#[AsWorkflow]` in your workflow namespace is auto-registered when you tag the folder:

```yaml
# config/services.yaml
App\Workflow\:
    resource: '../src/Workflow/'
    exclude: '../src/Workflow/Activity/'
    tags: [durable.workflow]
```

The `exclude` matters. The tag is not a filter: every service it matches is handed to the workflow
registry, which requires exactly one `#[AsWorkflowMethod]` and throws otherwise. Tag a folder that
also holds your activity handlers and the container stops building, with an error naming a class you
never meant to register. Keep the tagged folder to workflows, or exclude what is not one.

### Register activity implementations

Nothing to write. A class carrying `#[AsActivityHandler]` is picked up by the bundle's autoconfiguration as soon as it is a service — which, with the default `autoconfigure: true` of a Symfony application, it already is. This is where workflows above differ: those still need the tag.

---

## First workflow

### 1 — Define an activity contract

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;

// Optional: prefixes the names of the activities declared below.
#[AsActivity(name: 'greeting-activities')]
interface GreetingActivities
{
    #[AsActivityMethod(name: 'greet')]
    public function greet(string $name): string;
}
```

### 2 — Implement the activity

```php
<?php

declare(strict_types=1);

namespace App\Workflow\Activity;

use Gplanchat\Durable\Attribute\AsActivityHandler;

// This attribute is what registers the class; the bundle autoconfigures it.
#[AsActivityHandler(contract: GreetingActivities::class)]
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

        // 202: the run is queued, not done. Answering 200 here is the first thing that makes a
        // caller poll for a result that no consumer has produced yet.
        return new JsonResponse(['executionId' => $executionId], JsonResponse::HTTP_ACCEPTED);
    }
}
```

### 5 — Run a consumer, or nothing happens

`dispatchNewWorkflowRun()` returns `void`, and does exactly what its name says: it *dispatches*. The
workflow runs when something consumes the transports you configured above. Until then the execution
sits in a queue — a dashboard will call it `RUNNING`, which is true and unhelpful: it means *not
finished*, not *someone is working on it*.

```bash
php bin/console messenger:consume durable_workflows durable_activities
```

Those two names are the transports **you** declared in `messenger.yaml`. No document can hand you
this command without you having written that file first, which is why it is easy to look for the
missing piece everywhere except in your own configuration.

To see what the engine holds for one run:

```bash
php bin/console durable:execution:diagnose greet-abc123
```

#### Which profile are you in?

Two configurations work. Mixing them is the usual first stumble, and it fails silently.

**One process — tests.** `in-memory://` transports with the in-memory stores. Dispatch, resume and
activity all happen inside a single PHP process, so a test can dispatch and drain in one go. An
in-memory transport **does not outlive its process**: dispatching from a web request and consuming
in a separate worker cannot work here, and neither can replay — the journal the worker would need
lives in the web process's memory.

**Several processes — local dev and production.** Real transports **and** a durable store. Both, or
the worker picks up a queue entry naming a workflow whose journal it cannot see.

This profile needs two packages the quick start above does not install — the DBAL journal, and
DoctrineBundle for the `doctrine.dbal.default_connection` service it names:

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

The rule behind both profiles: **an execution survives exactly what its journal and its queue
survive.** Route `ResumeWorkflowMessage` or `ActivityMessage` to a transport a separate worker
cannot read, and the workflow replays inside the web request that started it and dies with the
process — the very failure durable execution exists to remove.

---

## Start Temporal workers (production / dev mode)

When `DURABLE_DSN` points to a Temporal server, start the Messenger consumers in separate processes.
**These are the Symfony commands** — the other hosts poll the same cluster with their own:
`php artisan durable:temporal-worker` on Laravel, `bin/magento durable:worker --role=journal` and
`--role=activity` on Magento.

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
- [Backends](../backends/) — In-Memory, DBAL, Illuminate and Temporal: when to use each, Docker Compose setup.
