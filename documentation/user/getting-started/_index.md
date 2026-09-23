---
title: Getting started
weight: 10
---

# Getting started

## What you need

- **PHP 8.2+**
- **Composer**
- For tests and local development: no additional infrastructure, since the **In-Memory** backend runs fully inside PHP.
- For production **without a cluster**: one SQL database, through the **DBAL** backend on Symfony or the **Illuminate** backend on Laravel. No extension to compile.
- For production **at scale**, or realistic integration tests: a **Temporal** cluster (Docker image available) and the **`ext-grpc`** PHP extension. In a container image, copy it from a [prebuilt image](../container-images/) rather than compiling it.

The four backends run the same workflow code; [Backends](../backends/) compares what each one can
offer.

---

## Install

**This page walks through the Symfony integration.** Durable has three host integrations, and
installing the wrong one is the mistake to avoid on the first line, because each has its own wiring,
its own configuration file and its own worker:

| Your application | Install | Read instead |
|---|---|---|
| **Symfony** (incl. Sylius) | `gplanchat/durable-bundle` | this page |
| **Laravel** | `gplanchat/durable-laravel` | [Packages](../packages/#gplanchatdurable-laravel--the-laravel-integration) |
| **Magento 2.4 / Mage-OS** | `gplanchat/durable-magento` | [Packages](../packages/#gplanchatdurable-magento--the-magento-integration) |
| **No framework** | `gplanchat/durable` | [Packages](../packages/#gplanchatdurable--the-library) |

The concepts, the workflow API and the activity API are identical on all four; only the wiring
below is Symfony's.

### Core component only (framework-agnostic)

```bash
composer require gplanchat/durable
```

### Symfony integration

```bash
composer require gplanchat/durable-bundle
```

The package declares `"type": "symfony-bundle"`, so **Symfony Flex registers it on its own**, and there
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

Turn Temporal on for an environment by giving it the DSN. The DSN is read when the container is
compiled, so an environment that has this line is a Temporal environment, even with an empty
`DURABLE_DSN`:

```yaml
when@dev:
    durable:
        temporal:
            dsn: '%env(DURABLE_DSN)%'
```

### `config/packages/messenger.yaml`

Durable uses **Symfony Messenger** to route internal messages. Under Temporal, the bundle registers
the `durable_workflows` and `durable_activities` workers itself; the two Messenger transports of the
same name, and their routing, belong only to the environments without a cluster — `test` here. An
environment with a DSN that declares them refuses to compile.

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

For Temporal (`dev`/`prod`):

```yaml
# .env.dev (or .env.local)
DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
```

---

## Register workflows and activities

### Tag workflows

Nothing to write. A class carrying `#[AsWorkflow]` is registered as soon as it is a service, which
with the default `autoconfigure: true` of a Symfony application it already is.

Earlier versions required tagging the folder by hand:

```yaml
# config/services.yaml — no longer needed
App\Workflow\:
    resource: '../src/Workflow/'
    exclude: '../src/Workflow/Activity/'
    tags: [durable.workflow]
```

The tag still works, so an application that writes it keeps working; it is simply redundant. If
you do keep it, the `exclude` still matters: the tag is not a filter, every service it matches is
handed to the workflow registry, which requires exactly one `#[AsWorkflowMethod]` and throws
otherwise.

### Register activity implementations

Nothing to write. A class carrying `#[AsActivityHandler]` is picked up by the bundle's autoconfiguration as soon as it is a service, which, with the default `autoconfigure: true` of a Symfony application, it already is.

---

## First workflow

### 1. Define an activity contract {#1--define-an-activity-contract}

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

### 2. Implement the activity {#2--implement-the-activity}

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

### 3. Define the workflow {#3--define-the-workflow}

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

### 4. Dispatch from a controller or service {#4--dispatch-from-a-controller-or-service}

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

### 5. Run a consumer, or nothing happens

`dispatchNewWorkflowRun()` returns `void`, and does exactly what its name says: it *dispatches*. The
workflow runs when something consumes the transports you configured above. Until then the execution
sits in a queue, and a dashboard will call it `RUNNING`, which is true and unhelpful: it means *not
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

**One process, for tests.** `in-memory://` transports with the in-memory stores. Dispatch, resume and
activity all happen inside a single PHP process, so a test can dispatch and drain in one go. An
in-memory transport **does not outlive its process**: dispatching from a web request and consuming
in a separate worker cannot work here, and neither can replay: the journal the worker would need
lives in the web process's memory.

**Several processes, for local dev and production.** Real transports **and** a durable store. Both, or
the worker picks up a queue entry naming a workflow whose journal it cannot see.

This profile needs two packages the quick start above does not install: the DBAL journal, and
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

The two queues move out of `when@test:` into this environment, on Doctrine, with the same routing:

```yaml
framework:
    messenger:
        transports:
            durable_workflows:  'doctrine://default?queue_name=durable_workflows'
            durable_activities: 'doctrine://default?queue_name=durable_activities'
```

The rule behind both profiles: **an execution survives exactly what its journal and its queue
survive.** Route `ResumeWorkflowMessage` or `ActivityMessage` to a transport a separate worker
cannot read, and the workflow replays inside the web request that started it and dies with the
process, the very failure durable execution exists to remove.

---

## Start Temporal workers (production / dev mode)

When `DURABLE_DSN` points to a Temporal server, start the workers the bundle registered, in separate processes.
**These are the Symfony commands**; the other hosts poll the same cluster with their own:
`php artisan durable:temporal-worker` on Laravel, `bin/magento durable:worker --role=journal` and
`--role=activity` on Magento.

```bash
# Workflow task worker (polls Temporal for workflow tasks)
php bin/console messenger:consume durable_workflows

# Activity worker (polls Temporal for activity tasks)
php bin/console messenger:consume durable_activities
```

An application that [serves a Nexus operation](../nexus/) starts a third one, `durable_nexus`.

For local development with `symfony serve`, add to `.symfony.local.yaml`:

```yaml
workers:
    workflows:
        cmd: ['symfony', 'console', 'messenger:consume', 'durable_workflows', '--time-limit=3600']
    activities:
        cmd: ['symfony', 'console', 'messenger:consume', 'durable_activities', '--time-limit=3600']
```

---

## Next steps

- [Concepts](../concepts/) covers the replay model, backends and event history in plain language.
- [Creating a workflow](../workflows/) covers the full workflow API: signals, queries, updates, child workflows, timers.
- [Creating activities](../activities/) covers `ActivityOptions`, retries, timeouts and dependency injection.
- [Testing workflows](../testing/) covers `DurableTestCase`, `ActivitySpy` and `DurableBundleTestTrait`.
- [Configuration reference](../configuration/) explains every `durable.yaml` key.
- [Backends](../backends/) covers In-Memory, DBAL, Illuminate and Temporal: when to use each, Docker Compose setup.
