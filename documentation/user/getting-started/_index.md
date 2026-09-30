---
title: Getting started
weight: 10
---

# Getting started

This tutorial takes you from an empty Symfony application to a first workflow that has run to
completion. You install the bundle, configure it, write one activity and one workflow, start the
workflow from a controller, and run the worker that executes it. A workflow is a PHP method whose
progress survives restarts. An activity is a call with a side effect, such as an HTTP request or an
e-mail. The journal records every step and its result. The [glossary](../glossary/) defines each
term.

## What you need

- **PHP 8.2+**
- **Composer**
- For tests: no additional infrastructure. The **In-Memory** backend runs entirely inside one PHP process.
- For local development and production **without a cluster**: one SQL database, through the **DBAL** backend on Symfony or the **Illuminate** backend on Laravel. No extension to compile.
- With a cluster, for production **at scale** or realistic integration tests: a **Temporal** cluster (Docker image available) and the **`ext-grpc`** PHP extension. In a container image, copy the extension from a [prebuilt image](../container-images/) instead of compiling it.

The four backends run the same workflow code. [Backends](../backends/) compares what each one
offers.

---

## Install

**This page follows the Symfony integration.** Durable has three host integrations, and each one
has its own wiring, its own configuration file and its own worker. Check the package for your
application before you run anything:

| Your application | Install | Read instead |
|---|---|---|
| **Symfony** (incl. Sylius) | `gplanchat/durable-bundle` | this page |
| **Laravel** | `gplanchat/durable-laravel` | [Packages](../packages/#gplanchatdurable-laravel--the-laravel-integration) |
| **Magento 2.4 / Mage-OS** | `gplanchat/durable-magento` | [Packages](../packages/#gplanchatdurable-magento--the-magento-integration) |
| **No framework** | `gplanchat/durable` | [Packages](../packages/#gplanchatdurable--the-library) |

The concepts, the workflow API and the activity API are the same on all four. Only the wiring below
is specific to Symfony.

Each block below starts with two `composer config` lines. Durable is on its beta line, and each
package requires its sibling packages from that same line. A stability flag such as `@beta` on the
require line applies only to the package it is written on, not to its dependencies. A project on the
default `stable` minimum stability therefore refuses the install until it allows beta releases.

### Core component only (framework-agnostic)
```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable
```

### Symfony integration
```bash
composer config minimum-stability beta
composer config prefer-stable true
composer config extra.symfony.allow-contrib true
composer require gplanchat/durable-bundle
```


The third line allows contrib recipes. The bundle's Flex recipe is in `symfony/recipes-contrib`, and
Flex asks before it runs a contrib recipe. The default answer is no, and it is the only answer under
`--no-interaction`. Without that line, the install prints `IGNORING gplanchat/durable-bundle`,
`config/bundles.php` does not list the bundle, and the configuration below fails with *There is no
extension able to load the configuration for "durable"*. If you see that message, nothing else is
broken: allow contrib recipes as above and reinstall, answer `y` at the prompt, or add the line to
`config/bundles.php` yourself:
```php
return [
    // ...
    Gplanchat\Durable\Bundle\DurableBundle::class => ['all' => true],
];
```


The recipe also writes a `config/packages/durable.yaml` and two `MESSENGER_DURABLE_*_DSN` lines in
`.env`. The files below **replace** that `durable.yaml`. The recipe's version names the stores with
`event_store.type` and `workflow_metadata.type`, which are deprecated since `backend` replaced them.
Once it is replaced, nothing reads the two `.env` lines: delete them.

---

## Minimal Symfony configuration

### `config/packages/durable.yaml`

The bundle uses the **In-Memory** backend by default. It keeps nothing between two processes, so use
it for tests only. [Which profile are you in?](#which-profile-are-you-in) gives the backend for local
development.
```yaml
durable:
    backend: in_memory       # 'dbal' or 'temporal' in the profiles below
    activity_transport:
        type: messenger
        transport_name: durable_activities
    child_workflow:
        async_messenger: true
    activity_contracts:
        cache: cache.app
        contracts:
            - App\Workflow\Activity\GreetingActivities   # list your activity interfaces here
```


To run an environment on Temporal, name the backend and give it the DSN. The DSN is read when the
container is compiled: an environment that has these lines runs on Temporal, even with an empty
`DURABLE_DSN`.
```yaml
when@dev:
    durable:
        backend: temporal
        temporal:
            dsn: '%env(DURABLE_DSN)%'
```


### `config/packages/messenger.yaml`

Durable routes its internal messages through **Symfony Messenger**. Under Temporal, the bundle
registers the `durable_workflows` and `durable_activities` workers itself. The two Messenger
transports with those names, and their routing, belong only to environments without a cluster, which
is `test` here. An environment that has a DSN and declares them fails to compile.
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

Each host registers the three kinds of class in its own way:

| | Symfony | Laravel (`config/durable.php`) | Magento (`di.xml`, `RuntimeFactory`) |
|---|---|---|---|
| workflow | `#[AsWorkflow]` on a service, autoconfigured | listed in `workflows` | listed in the `workflowClasses` argument |
| activity handler | `#[AsActivityHandler]` on a service, autoconfigured | listed in `activity_handlers` | listed in the `activityHandlers` argument |
| Nexus handler | `#[AsNexusServiceHandler]` on a service, autoconfigured | listed in `nexus.handlers` | listed in the `nexusHandlers` argument |

Only Symfony registers a class from its attribute. Laravel and Magento scan nothing: a class they do
not list is not registered, whatever attribute it carries. On Laravel, `#[AsActivityHandler]` and
`#[AsNexusServiceHandler]` on a listed handler name the contract it serves. On Magento,
`#[AsNexusServiceHandler]` names it, and `#[AsActivityHandler]` may. The
[per-host table](../configuration/#host-table) lists every other setting. The rest of this section
follows the Symfony path.

### Tag workflows

You have nothing to add. A class with `#[AsWorkflow]` is registered as soon as it is a service, and
with the default `autoconfigure: true` of a Symfony application, it already is one.

Earlier versions required tagging the folder by hand:
```yaml
# config/services.yaml — no longer needed
App\Workflow\:
    resource: '../src/Workflow/'
    exclude: '../src/Workflow/Activity/'
    tags: [durable.workflow]
```


The tag still works, so an application that declares it keeps working; the tag is redundant. If you
keep it, keep the `exclude` as well. The tag does not filter anything: every service it matches goes
to the workflow registry, which requires exactly one `#[AsWorkflowMethod]` per class and throws an
exception otherwise.

### Register activity implementations

On Symfony, you have nothing to add. The bundle's autoconfiguration registers a class with `#[AsActivityHandler]` as soon as it is a service, and with the default `autoconfigure: true` of a Symfony application, it already is one.

---

## First workflow

The next five steps build a workflow that greets a name. The activity is the greeting. The workflow
calls it, and Durable records its result in the journal.

### 1. Define an activity contract {#1--define-an-activity-contract}

The contract is an interface. The workflow calls it; the handler in step 2 implements it.
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

// On Symfony, this attribute registers the class; Laravel and Magento list it instead.
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


`$name` comes from the input the workflow is started with. Durable supplies `$greeting` and `$env`,
the way Symfony supplies a controller's services. See
[Arguments Durable supplies](../workflows/#arguments-durable-supplies).

### 4. Dispatch from a controller or service {#4--dispatch-from-a-controller-or-service}

Start a run with `WorkflowResumeDispatcher::dispatchNewWorkflowRun()`. It is the only way to start a
run that works on every backend: in-memory, DBAL and Temporal. On Temporal, it calls the client's `startAsync()` for you. Call
`startAsync()` yourself only when you need its start options (timeouts, search attributes, cron). It
belongs to the Temporal client and exists on no other backend.

The second argument is the workflow's name: the name `#[AsWorkflow]` declares, or the short class
name when there is no attribute. You can also pass `GreetWorkflow::class`. The dispatcher resolves it
to the same name, so the journal, the dashboard and `durable:execution:diagnose` show `greet` in both
cases.
```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;

final class GreetController
{
    public function __construct(
        private readonly WorkflowResumeDispatcher $dispatcher,
    ) {}

    public function __invoke(string $name): JsonResponse
    {
        $executionId = ExecutionId::fromString('greet-'.uniqid());
        $this->dispatcher->dispatchNewWorkflowRun($executionId, 'greet', ['name' => $name]);

        // 202: the run is queued, not done. Answering 200 here is the first thing that makes a
        // caller poll for a result that no consumer has produced yet.
        return new JsonResponse(['executionId' => $executionId->toString()], JsonResponse::HTTP_ACCEPTED);
    }
}
```


The controller answers `202 Accepted` with the execution ID. The workflow has not run yet: it waits
in a queue until a worker consumes it, which is the next step.

### 5. Run a consumer, or nothing happens

`dispatchNewWorkflowRun()` returns `void` and only *dispatches* the run. The workflow runs when a
worker consumes the transports you configured above. Until then, the execution waits in a queue, and
a dashboard shows it as `RUNNING`. In that state, `RUNNING` means *not finished*; it does not mean a
worker is processing it.

Start the worker:
```bash
php bin/console durable:worker
```


The worker reads the transport names from your configuration: the transports `messenger.yaml` routes
`ResumeWorkflowMessage` and `FireWorkflowTimersMessage` to, and the `activity_transport` of
`durable.yaml`. It prints the transports it consumes (`Consuming durable_workflows,
durable_activities.`) and delegates the rest to `messenger:consume`, passing on `--limit`,
`--time-limit`, `--memory-limit`, `--failure-limit`, `--sleep` and `--no-reset`. If resumes are
routed nowhere, or only to `sync`, the worker does not start and prints why, instead of waiting on an
empty queue.

`messenger:consume durable_workflows durable_activities` still works: those two names are the
transports **you** declared in `messenger.yaml`. Signals and updates are not in that list, because
this guide routes them to `sync`. If you route them to an asynchronous transport of your own, consume
that transport yourself: `durable:worker` does not look for it.

Both commands belong to the **several-processes** profile described below: a worker in its own
process, on real transports. The `when@test` profile above puts both transports on `in-memory://`,
where a separate worker consumes nothing. An in-memory transport only holds the messages its own
process sent, and Messenger resets services after each message it handles, which empties the
in-memory queue. The activity the workflow queued disappears, and the run stays on
`ActivityScheduled`. In that profile, run the workflow to completion inside the test that dispatched
it (`DurableBundleTestTrait`, see [Testing workflows](../testing/)), or consume in that same process
with `--no-reset`. Without `--no-reset`, both commands refuse to start on an in-memory Durable
transport and print which of these two options to use.

To see what the engine holds for one run, pass its execution ID:
```bash
php bin/console durable:execution:diagnose greet-abc123
```


The command prints the run's metadata, its parent and child links, and the first events of its
journal with their payloads: the workflow's input, and each activity's arguments and result. For
this workflow, the result of the `greet` activity is `Hello, <name>!` with the name you passed to the
controller. When you see it in the journal, your first workflow has run to completion!

Values under keys such as `password`, `token`, `secret`, `authorization`, `card` or `api_key` are
masked, and long strings are truncated; `--raw` prints them as stored. Masking works by key name, so
personal data under other keys is shown: check where you paste the output. The web profiler panel
masks values the same way.

#### Which profile are you in?

Three configurations work, one for each stage. If you mix two of them, nothing reports an error: the
run fails silently.

**Tests: one process, in memory.** `in-memory://` transports with the in-memory stores. Dispatch,
resume and activity all run in a single PHP process, so a test dispatches and completes the run in
one go, with `DurableBundleTestTrait` or with `durable:worker --no-reset` in that process. The worker
commands of step 5 belong to the next profile. An in-memory transport **does not outlive its
process**: dispatching from a web request and consuming in a separate worker cannot work, and
neither can replay, because the journal the worker needs lives in the web process's memory.

**Local development, and production without a cluster: several processes, on DBAL.** Real
transports **and** a durable store. Configure both: with a real transport alone, the worker receives
a queue entry for a workflow whose journal it cannot read.

This profile needs packages the quick start above does not install: the DBAL journal, DoctrineBundle
for the `doctrine.dbal.default_connection` service the journal uses, and the Doctrine Messenger
transport for the `doctrine://` queues below. DoctrineBundle's recipe also configures the ORM, which
is why `doctrine/orm` is in the list. If you do not use the ORM, remove the `orm:` section of
`config/packages/doctrine.yaml` instead.
```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-bridge-dbal doctrine/doctrine-bundle doctrine/orm symfony/doctrine-messenger
```

```yaml
durable:
    backend: dbal
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


**With a Temporal cluster: the DSN only.** An environment where `durable.temporal.dsn` is set runs
on Temporal. The cluster holds the journal and the queues, and the
[workers below](#start-temporal-workers-production--dev-mode) poll it. The `when@dev` block above
puts `dev` in this profile; remove it to develop on DBAL instead.

All three profiles follow one rule: **an execution survives exactly what its journal and its queue
survive.** If you route `ResumeWorkflowMessage` or `ActivityMessage` to a transport that a separate
worker cannot read, the workflow replays inside the web request that started it, and stops when that
process stops.

---

## Start Temporal workers (production / dev mode)

When `DURABLE_DSN` points to a Temporal server, start the workers the bundle registered, each in its
own process. **These are the Symfony commands.** The other hosts poll the same cluster with their
own: `php artisan durable:temporal-worker` and `--role=activity` on Laravel,
`bin/magento durable:worker --role=journal` and `--role=activity` on Magento.
```bash
# Workflow task worker (polls Temporal for workflow tasks)
php bin/console durable:worker --role=workflow

# Activity worker (polls Temporal for activity tasks)
php bin/console durable:worker --role=activity
```


An application that [serves a Nexus operation](../nexus/) starts a third worker, `--role=nexus`.
These workers are the receivers `durable_workflows`, `durable_activities` and `durable_nexus`, and
`messenger:consume` accepts those names too.

On Temporal, run **one process per role**. Each receiver long-polls the cluster, and one worker polls
its receivers in turn. In a single process, a workflow task can wait until an idle activity poll
times out before it is picked up. For the same reason, `--limit` and `--failure-limit` never stop a
Temporal worker, because its receivers pass no message to Messenger. `--time-limit` and
`--memory-limit` stop it once the current long poll returns.

For local development with `symfony serve`, add this to `.symfony.local.yaml`:
```yaml
workers:
    workflows:
        cmd: ['symfony', 'console', 'durable:worker', '--role=workflow', '--time-limit=3600']
    activities:
        cmd: ['symfony', 'console', 'durable:worker', '--role=activity', '--time-limit=3600']
```


---

## Next steps

- [Concepts](../concepts/): the replay model, backends and event history.
- [Creating a workflow](../workflows/): the full workflow API, with signals, queries, updates, child workflows and timers.
- [Creating activities](../activities/): `ActivityOptions`, retries, timeouts and dependency injection.
- [Testing workflows](../testing/): `DurableTestCase`, `ActivitySpy` and `DurableBundleTestTrait`.
- [Configuration reference](../configuration/): every `durable.yaml` key.
- [Backends](../backends/): In-Memory, DBAL, Illuminate and Temporal, when to use each, and the Docker Compose setup.
