---
title: Packages
weight: 5
---

# Packages

Durable is a core, an optional framework integration, and a choice of backend. You take what you
need: the library alone is enough to write and unit-test a workflow, and nothing above it changes
the workflow code — only where the execution is recorded.

| Package | Brings | Needs |
|---|---|---|
| `gplanchat/durable` | workflows, activities, timers, event journal, in-memory backend | `psr/cache` |
| `gplanchat/durable-bundle` | Symfony wiring, worker commands, profiler panel | the library, Symfony framework-bundle and Messenger |
| `gplanchat/durable-bridge-temporal` | the Temporal driver, over gRPC | the library, `ext-grpc`, a Temporal cluster |
| `gplanchat/durable-bridge-dbal` | durable execution on one SQL database | the library, Doctrine DBAL 3 or 4, `symfony/lock` |
| `gplanchat/durable-bridge-illuminate` | the same, on the connection Laravel already owns | the library, `illuminate/database` 11, 12 or 13 |
| `gplanchat/durable-laravel` | the Laravel wiring: ports bound from config, work on the application's queue | the library, the Illuminate bridge, `illuminate/support` |
| `gplanchat/durable-magento` | a Magento 2.4 / Mage-OS module: declaration, workers, admin screen | the library; Temporal for anything that must outlive a process |
| `gplanchat/durable-plugin` | a Sylius admin dashboard for workflow runs | the bundle, `knplabs/knp-menu`; Sylius 2.x to appear in its menu |

The three bridges are **alternatives**, not layers: you pick Temporal, DBAL or Illuminate, never
two of them.

---

## `gplanchat/durable` — the library

```bash
composer require gplanchat/durable
```

The engine and the whole domain: `WorkflowEnvironment`, activities, timers, side effects, signals,
queries, updates, child workflows, the event journal, and the value objects that describe
scheduling options.

One runtime dependency — `psr/cache`, and even that pool is optional: it memoises activity
contract resolution, and `ActivityContractResolver` works without one. **No framework.** You can
drive the library from a plain PHP script, a Laminas application, a console tool, or a test.

It ships an **in-memory backend** that runs everything in one process. That is what your unit
tests use, and it needs nothing installed.

> [!NOTE]
> The in-memory backend keeps no state between processes. It is for tests and local exploration,
> not for a workflow that has to survive a deploy. See [Backends](../backends/).

---

## `gplanchat/durable-bundle` — the Symfony integration

```bash
composer require gplanchat/durable-bundle
```

What it does that you would otherwise write by hand:

- **Autoconfiguration.** Classes carrying `#[AsWorkflow]` and `#[AsActivity]` are registered on their
  own; you do not list them in a container file.
- **Messenger wiring.** Workflow resumes and activity dispatches are routed to the transports you
  name in `durable.yaml`, so a workflow that suspends resumes through your existing queues.
- **Worker commands.** Console entry points to run workflow and activity workers.
- **Profiler panel.** In the Symfony toolbar: each execution, its journal, and the timeline of
  activities — including which attempt failed and why.

Configuration is one file, documented key by key in the
[configuration reference](../configuration/).

---

## `gplanchat/durable-bridge-temporal` — the Temporal driver

```bash
composer require gplanchat/durable-bridge-temporal
```

Talks to a Temporal cluster **directly over gRPC**. There is no official Temporal PHP SDK in the
dependency tree, and no RoadRunner — the protobuf definitions are vendored and the workers are
plain PHP processes.

What it adds over the in-memory backend:

- executions that survive process restarts, deploys and crashes;
- server-side retry policies, so a failing activity is retried even if the worker is gone;
- cron schedules, search attributes, and cross-process visibility in the Temporal UI;
- a read-through event store, so the profiler shows a real execution's history.

It needs `ext-grpc` and a reachable cluster. For local work, one command is enough:

```bash
temporal server start-dev --namespace durable-test --port 7233
```

> [!NOTE]
> Cron schedules and search attributes are Temporal capabilities. They have no in-process
> equivalent and the in-memory backend refuses them explicitly rather than ignoring them silently.

---

## `gplanchat/durable-bridge-dbal` — the SQL backend

```bash
composer require gplanchat/durable-bridge-dbal
```

Durable execution on **one SQL database**, with no orchestration cluster and no `ext-grpc`. The
decision behind it is [**DUR030**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md).

The replay interpreter, the workflow ports and the command buffer are untouched: this bridge only
makes the three process-local stores persistent — the event journal, the workflow metadata, and
the parent links between child workflows. Workflow and activity code is byte-for-byte what runs on
Temporal or in memory.

| Kept | Given up, against Temporal |
|---|---|
| Workflow classes, activities, `WorkflowEnvironment` | Distributed task queues — resumes ride Symfony Messenger |
| Signals, queries, updates | Server-side scheduling; timers ride Messenger `DelayStamp` |
| Cancellation and compensation semantics | Server-side task serialisation, replaced by an application lock |
| Replay determinism and the event journal | History retention, visibility API, the Temporal UI |

Choose it when durability matters and running a cluster does not: one database you already back
up, one migration, and no extension to compile.

---

## `gplanchat/durable-bridge-illuminate` — the Laravel backend

```bash
composer require gplanchat/durable gplanchat/durable-bridge-illuminate
php artisan migrate
```

The same four stores as the DBAL bridge, and the same trade against Temporal — read that table
above, it applies here word for word. What changes is the connection: these are written against
`Illuminate\Database\Connection`, the query builder rather than Eloquent.

That is the whole reason the package exists. **DUR030** only pays if the journal append and the
business write land in **one transaction**, and a store on `DB::connection()` is inside
`DB::transaction()` by construction. Handing Doctrine DBAL the PDO out of
`DB::connection()->getPdo()` reaches the same guarantee and is a workaround; this is the plain
answer.

The four tables ship as a migration loaded straight from the package, so `migrate` is enough.
`vendor:publish --tag=durable-migrations` is for when you want to edit them — and from that point
they are yours. **Keep the published file's name**: Laravel keys migrations by basename
and lets `database/migrations` win the tie, which is what makes your copy the one that runs. Rename
it and both run, the second failing on a table that already exists.

`Queue\ResumeLock` is the one thing no choice of storage supplies. Two workers resuming the **same**
execution both replay it, both believe they are discovering the commands it produces, and those
commands go out twice; the journal does not prevent it, since it faithfully records whatever it is
handed, twice included. It takes a closure, so a queued job, an artisan command or a hand-written
worker can all use it.

> [!NOTE]
> **There is no Laravel integration package yet.** This is a set of stores, not a wiring: nothing
> binds the ports, no worker command, no jobs. `DurableIlluminateServiceProvider` registers exactly
> one thing — where the migrations live. Until an integration package exists you wire the stores
> yourself, the way a framework-less application does.

---

## `gplanchat/durable-plugin` — the Sylius dashboard

```bash
composer require gplanchat/durable-plugin
```

An admin dashboard for Sylius: the list of workflow runs with search and status filters, and a run
detail view with timeline lanes and recent events. Timeline labels prefer the human-readable
`ActivityType.name` and fall back to technical IDs only when there is nothing better.

It **observes**; it does not execute. It requires `gplanchat/durable-bundle`, which wires the run
catalog it reads, so the command above is the whole install.

> [!NOTE]
> Live data comes from whichever backend is installed. No bridge is a `require` here — the
> backend is suggested by `gplanchat/durable`, once, for every integration. Without one the plugin
> still installs, the route and the menu entry still work, and the dashboard renders its degraded
> state instead of live runs.

## `gplanchat/durable-magento` — the Magento integration

```bash
composer require gplanchat/durable-magento
```

> [!WARNING]
> **Not published yet.** The package exists in the repository and runs on the bench; it is not on
> Packagist, so the command above does not resolve today. Everything below describes what is built,
> not what you can install.

A Magento 2.4 / Mage-OS module — `Gplanchat_DurableModule` in `bin/magento module:status`. It declares
workflow and activity classes to the runtime, assembles the engine for a Magento process, ships the
workers as `bin/magento` commands, and adds a read-only admin screen under
**System > Durable processes > Process history**.

The screen is a standard Magento grid — paging, bookmarks, column controls, export, and a
multi-select status filter whose options come from the status enum itself. Selecting a run opens its
detail: a timeline with **one line per action** — an activity scheduled, started and completed is
one line, and the line's bar is how long it took. The run itself is the first line, named after the
workflow and holding its workflow tasks; a child workflow keeps a line of its own. Each bar is cut
between consecutive events, so an interval in which nothing was recorded — waiting for a worker —
says how long it lasted rather than hiding inside a bar. The journal sits beneath it. Each journal line unfolds onto what the backend recorded with
it — the arguments an activity was called with, what it returned, the class and message of a
failure. Positioning by time rather than by rank is the point: it is what makes a run that spent
twenty-two of its twenty-four seconds waiting look like one.

Magento's container has no equivalent of Symfony's tag autoconfiguration, so declaration is
explicit — two arrays in `di.xml`:

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

The *contract* is not declared: the factory reads each handler's interfaces and keeps those carrying
`#[AsActivityMethod]`. One declaration fewer to get wrong, and the activity names stay the
attributes'.

**Two backends, and Composer enforces it.** Magento reaches in-memory and Temporal, and the module
declares `conflict` on both SQL bridges — `Magento\Framework\App\ResourceConnection` is neither
Doctrine DBAL nor Illuminate's connection. Which one you get is decided by a DSN in
`app/etc/env.php`, not by a setting:

```php
'durable' => [
    'temporal' => ['dsn' => 'temporal://temporal:7233?namespace=default&tls=0'],
],
```

Without it the journal lives in the process that writes it, and dies with it — fine for a console
command, ruinous for anything else.

**Workers are commands, not queue consumers**, and an operator supervises them like any other
long-running process:

```bash
bin/magento durable:worker --role=journal   --time-limit=3600
bin/magento durable:worker --role=activity  --time-limit=3600
```

One process, one queue, one role: they are two distinct Temporal queues, and their concurrency is
tuned apart. Nothing rides Magento's own `MessageQueue` — on Temporal an activity is a Temporal
command and a resume is a workflow task, so a topic here would be a second queue for an operator to
supervise, for nothing.

> [!NOTE]
> Start executions **on the cluster**, not in the request that triggers them. An observer on
> `sales_order_place_after` that calls `RuntimeFactory::workflowClient()->startAsync()` hands the
> execution to Temporal and returns; starting it inline would kill it with the request, which is the
> very failure this integration exists to remove.

---

## Which do I install?

Every command below is the one the chooser on the [home page](/) hands you, written out in full.

| Your situation | Command |
|---|---|
| Learning, or unit tests only | `composer require gplanchat/durable` |
| No framework, one SQL database | `composer require gplanchat/durable gplanchat/durable-bridge-dbal` |
| No framework, Temporal cluster | `composer require gplanchat/durable gplanchat/durable-bridge-temporal` |
| Symfony, tests only | `composer require gplanchat/durable-bundle` |
| Symfony, one SQL database | `composer require gplanchat/durable-bundle gplanchat/durable-bridge-dbal` |
| Symfony, Temporal cluster | `composer require gplanchat/durable-bundle gplanchat/durable-bridge-temporal` |
| Sylius, tests only | `composer require gplanchat/durable-plugin` |
| Sylius, one SQL database | `composer require gplanchat/durable-plugin gplanchat/durable-bridge-dbal` |
| Sylius, Temporal cluster | `composer require gplanchat/durable-plugin gplanchat/durable-bridge-temporal` |
| Laravel, one SQL database | `composer require gplanchat/durable gplanchat/durable-bridge-illuminate` |
| Magento, Temporal cluster | `composer require gplanchat/durable-magento gplanchat/durable-bridge-temporal` |

Each line names the integration only: the bundle pulls the library in, and the plugin pulls the
bundle in. Without a framework you name the library yourself, and you wire the workers yourself too.

The Laravel line names the library rather than an integration, and that is now a *choice* rather
than a gap: `gplanchat/durable-laravel` exists — a service provider binding the four storage ports,
workflows declared in `config/durable.php`, work riding the queue the application already drains.
Until it is tagged, the bridge installs on its own and you wire it yourself; see the section above
for what the integration takes off your hands.

---

## One codebase, one behaviour

Every backend runs the **same fiber driver** and the **same activity execution path**. A workflow
you tested in memory behaves the same way against DBAL or Temporal — retry counting, failure
classification, cancellation and compensation included.

Where a capability genuinely has no equivalent, the backend **fails with an explicit message**
rather than pretending. The differences are listed in
[Backends](../backends/#capability-matrix).

---

## Monorepo and releases

Development happens in a single repository, `gplanchat/durable-dev`. Each package is published to
its own read-only repository by a split, so `composer require` pulls a small package rather than
the whole tree.
