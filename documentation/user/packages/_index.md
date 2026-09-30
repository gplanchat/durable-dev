---
title: Packages
weight: 5
---

# Packages

Durable consists of a core library, an optional framework integration, and a choice of backend.
The backend is where an execution's journal lives and what schedules its work; the journal is the
append-only record of every step an execution took (see the [glossary](../glossary/)). Install what
you need: the library alone is enough to write and unit-test a workflow, and the packages above it
change only where the execution is recorded, never the workflow code.

| Package | Brings | Needs |
|---|---|---|
| `gplanchat/durable` | workflows, activities, timers, event journal, in-memory backend | `psr/cache` |
| `gplanchat/durable-bundle` | Symfony wiring, Messenger transports, profiler panel | the library and Symfony Messenger |
| `gplanchat/durable-bridge-temporal` | the Temporal driver, over gRPC | the library, `ext-grpc`, a Temporal cluster |
| `gplanchat/durable-bridge-dbal` | durable execution on one SQL database | the library, Doctrine DBAL 3 or 4, `symfony/lock` |
| `gplanchat/durable-bridge-illuminate` | the same, through Laravel's database layer | the library, `illuminate/database` 11, 12 or 13 |
| `gplanchat/durable-laravel` | the Laravel wiring: ports bound from config, work on the application's queue | the library, `illuminate/support`; the Illuminate or Temporal bridge for the backend you select |
| `gplanchat/durable-magento` | a Magento 2.4 / Mage-OS module: declaration, workers, admin screen | the library; Temporal for anything that must outlive a process |
| `gplanchat/durable-plugin` | a Sylius admin dashboard for workflow runs | the bundle, `knplabs/knp-menu`; Sylius 2.x to appear in its menu |
| `gplanchat/durable-filament` | a Filament panel dashboard for workflow runs | the Laravel integration, Filament 3 or 4 |
| `gplanchat/durable-phpstan` | static analysis of stub calls against their contract | the library, `phpstan/phpstan` |
| `gplanchat/durable-rector` | automated migration off the Temporal PHP SDK | the library, `rector/rector` |

A workflow is the PHP class that describes an execution's steps, and an activity is a unit of side
effect that a workflow calls, such as an HTTP call or a database write.

The three bridges are **alternatives** and do not stack: you install Temporal, DBAL or Illuminate, never two of them.

The last two packages are **development-time tools** and belong in `require-dev`:

- **`gplanchat/durable-phpstan`** resolves `activityStub()` and `childWorkflowStub()` calls against
  the contract interface. A mistyped activity or a wrong argument then shows up as an analysis
  error instead of a serialization failure at runtime. It also checks that an
  [`#[Activities]` parameter](../workflows/#arguments-durable-supplies) and its
  `@param ActivityStub<Contract>` docblock name the same contract.
- **`gplanchat/durable-rector`** migrates a project off the official Temporal PHP SDK. It rewrites
  the attributes and changes the execution model, and it keeps the workflow and activity type names
  that a running server already has on record. It leaves a comment on each construct it cannot
  convert, so you see them before you start. See [the comparison page](../comparison/#choosing).

---

## `gplanchat/durable`, the library {#gplanchatdurable--the-library}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable
```

The library contains the engine and the whole domain: `WorkflowEnvironment`, activities, timers,
side effects, signals, queries, updates, child workflows, the event journal, and the value objects
that describe scheduling options.

It has one runtime dependency, `psr/cache`, and the cache pool itself is optional: it memoises
activity contract resolution, and `ActivityContractResolver` works without one. The library needs
**no framework**. You can drive it from a plain PHP script, a Laminas application, a console tool,
or a test.

It includes an **in-memory backend** that runs everything in one process. Your unit tests use it,
and it needs nothing else installed.

> [!NOTE]
> The in-memory backend keeps no state between processes. Use it for tests and local exploration;
> a workflow that has to survive a deploy needs another backend. See [Backends](../backends/).

---

## `gplanchat/durable-bundle`, the Symfony integration {#gplanchatdurable-bundle--the-symfony-integration}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-bundle
```

The bundle does the following, which you would otherwise write by hand:

- **Autoconfiguration.** The bundle registers every class carrying `#[AsWorkflow]` or
  `#[AsActivityHandler]`. You neither list these classes in a container file nor tag them.
  `#[AsActivity]` names the contract and registers nothing.
- **Messenger wiring.** Workflow resumes and activity dispatches go to the transports you name in
  `durable.yaml`, so a workflow that suspends resumes through your existing queues.
- **One console command.** `durable:execution:diagnose <executionId>` prints what the engine holds
  for one run: its workflow metadata, its parent/child links and its event journal. The bundle adds
  no worker command; the worker is Messenger's own `messenger:consume` on the transports above.
- **Profiler panel.** In the Symfony toolbar, the panel shows each execution, its journal, and the
  timeline of its activities, including which attempt failed and why.

All configuration lives in one file, documented key by key in the
[configuration reference](../configuration/).

---

## `gplanchat/durable-bridge-temporal`, the Temporal driver {#gplanchatdurable-bridge-temporal--the-temporal-driver}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-bridge-temporal
```

The bridge talks to a Temporal cluster **directly over gRPC**. The dependency tree contains neither
the official Temporal PHP SDK nor RoadRunner: the protobuf definitions are vendored and the workers
are plain PHP processes.

Compared with the in-memory backend, it adds:

- executions that survive process restarts, deploys and crashes;
- server-side retry policies, so a failing activity is retried even if the worker is gone;
- cron schedules, search attributes, and cross-process visibility in the Temporal UI;
- a read-through event store, so the profiler shows a real execution's history.

It requires `ext-grpc` and a reachable cluster. For local work, one command starts a development
server:

```bash
temporal server start-dev --namespace durable-test --port 7233
```

> [!NOTE]
> Cron schedules and search attributes are Temporal capabilities with no in-process equivalent. The
> in-memory backend rejects them with an explicit error instead of ignoring them silently.

---

## `gplanchat/durable-bridge-dbal`, the SQL backend {#gplanchatdurable-bridge-dbal--the-sql-backend}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-bridge-dbal
```

The bridge provides durable execution on **one SQL database**, without an orchestration cluster or
`ext-grpc`. [**DUR030**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md)
records the decision behind it.

The bridge leaves the replay interpreter, the workflow ports and the command buffer unchanged.
Replay is how an execution resumes: the workflow code runs again from its first line, and each
recorded step returns its result from the journal. The bridge only makes three process-local stores
persistent: the event journal, the workflow metadata, and the parent links between child workflows.
Workflow and activity code is byte-for-byte what runs on Temporal or in memory.

| Kept | Given up, compared with Temporal |
|---|---|
| Workflow classes, activities, `WorkflowEnvironment` | Distributed task queues; resumes go through Symfony Messenger |
| Signals, queries, updates | Server-side scheduling; timers use Messenger's `DelayStamp` |
| Cancellation and compensation semantics | Server-side task serialisation, replaced by an application lock |
| Replay determinism and the event journal | History retention, visibility API, the Temporal UI |

Choose it when you need durability without running a cluster. It takes one database you already
back up, one migration, and no extension to compile.

---

## `gplanchat/durable-bridge-illuminate`, the Laravel backend {#gplanchatdurable-bridge-illuminate--the-laravel-backend}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable gplanchat/durable-bridge-illuminate
php artisan migrate
```

This bridge provides the same four stores as the DBAL bridge, with the same trade-offs against
Temporal: the table above applies here word for word. The connection differs. These stores use
`Illuminate\Database\Connection` and its query builder, without Eloquent.

Give the stores their own connection in `config/database.php`, separate from the application's
default one (DUR054). On a shared connection, Durable's own transactions nest inside the
application's: a business rollback erases journal events, and a claim stays invisible to the other
workers until the business code commits. To handle an activity that writes and then dies, make the
activity idempotent. Never share a transaction with business code for that purpose.

The four tables ship as a migration loaded straight from the package, so `migrate` is enough. To
edit them, publish them with `vendor:publish --tag=durable-migrations`; from then on, you maintain
the published copy. **Keep the published file's name.** Laravel keys migrations by basename and
gives precedence to `database/migrations` when two names match, which makes your copy the one that
runs. If you rename it, both migrations run, and the second fails on a table that already exists.

`ResumeLock` covers what no choice of storage supplies. It lives in [`gplanchat/durable-laravel`](#gplanchatdurable-laravel--the-laravel-integration), which uses it on every backend. When two workers resume the **same**
execution, both replay it, both treat the commands it produces as new, and those commands go out
twice. The journal does not prevent this, because it records whatever it receives, duplicates
included. `ResumeLock` takes a closure, so a queued job, an artisan command or a hand-written worker
can all use it.

> [!NOTE]
> **This bridge provides the storage only.** It binds no ports and ships no worker command and no
> job; `DurableIlluminateServiceProvider` registers only the location of the migrations.
> [`gplanchat/durable-laravel`](#gplanchatdurable-laravel--the-laravel-integration), described in
> the next section, binds them. If you install the bridge alone, you wire the stores yourself, as a
> framework-less application does.

---

## `gplanchat/durable-laravel`, the Laravel integration {#gplanchatdurable-laravel--the-laravel-integration}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-laravel gplanchat/durable-bridge-illuminate
php artisan migrate
php artisan vendor:publish --tag=durable-config
```

The package suggests the two bridges and requires neither, so you install the one of the backend you
select: `gplanchat/durable-bridge-illuminate` for one SQL database, as above, or
`gplanchat/durable-bridge-temporal` for a Temporal cluster. In memory needs neither. With `backend`
set to `illuminate`, the default, and the bridge missing, registration fails with the command to run.

Package auto-discovery registers the provider. From one published `config/durable.php`, the
provider binds the four storage ports, the activity and resume jobs, and the per-execution lock.

**One `backend` value binds every port.** A journal on one backend with a run catalogue on another
is a fault, so `backend` takes a single value. A value this package does not serve fails at
registration with an error that names it and the three backends the package serves: `illuminate`, `memory`
and `temporal`.

**You declare workflows in configuration.** Laravel has no equivalent of Symfony's attribute
autoconfiguration, so the `workflows` key names the classes. Naming them costs 0,14 ms, measured,
and does not grow with the application. A reflection scan costs 15 ms at a thousand classes **and
loads all of them into every process** to find five. For the same reason, there is no
`durable:cache`: `config:cache` already caches the file it would duplicate.

**Work runs on the queue the application already drains**, with `php artisan queue:work` as the
only worker. Activities and resumes are jobs; a timer is a deferred timer-firing job that uses the
queue's own delay.

### Comparison with `durable-workflow/workflow` {#it-is-not-a-durable-engine-for-laravel-and-that-square-is-taken}

[`durable-workflow/workflow`](https://github.com/durable-workflow/workflow), formerly
`laravel-workflow/laravel-workflow`, provides durable execution **on Laravel queues**, with its own
storage. It is explicitly inspired by Temporal and Azure Durable Functions and has more than a
thousand stars. Since 2.0 it writes workflows as straight-line methods on Fibers, and it runs
embedded in your application, on its own standalone server, or on its managed Cloud, with PHP,
Python and Rust SDKs. It ships a monitoring UI, Waterline. It does its job well; if you want a
Laravel-first engine, choose it.

`gplanchat/durable-laravel` offers a **different backend choice**. The same workflow code runs
against a Temporal cluster (Temporal Cloud and Nexus included, with a history the Temporal UI
reads) *or* against a SQL database, with no cluster to run. A mixed Symfony / Sylius / Laravel
estate also shares a single engine: a workflow class written for `gplanchat/durable-bundle` runs
here unmodified. These two points are the package's whole claim, and `durable-workflow/workflow`
does not make it.

This section exists because the two packages have neighbouring names on Packagist.

### Starting a run

`WorkflowResumeDispatcher::dispatchNewWorkflowRun()` starts a run on every backend:

- on `illuminate`, it queues the first resume for `queue:work`;
- on `temporal`, it starts the workflow on the cluster, which delivers everything after that;
- on `memory`, it drives the run **in the caller's process**: the call returns once the run has
  completed, or once it waits on a signal or on something due later than the ten-second drain
  budget. This backend's journal lives in the process, so nothing outside the process can advance
  the run.

### Serving Nexus operations {#nexus-on-the-backend-that-can-route-it}

A Nexus operation is an operation served by another service, with its own contract, that a
workflow calls the way it calls an activity (see the [glossary](../glossary/)). Serving one means
answering a call that arrives from another namespace, and only the cluster routes those calls. The
`nexus.handlers` key names the handlers and the contracts they serve:

```php
'nexus' => ['handlers' => [App\Nexus\BillingHandler::class => App\Contracts\BillingService::class]],
```

A workflow fulfils the operations that no handler serves. It carries `#[FulfilsNexusOperation]`,
and listing it in `workflows` above is enough. A contract splits into two interfaces because PHP
has no way to express a partial implementation; the registry puts the two halves back together.

**A Nexus declaration under a backend that cannot route fails at registration**, before the first
call, with an error that names the backend. Calling a Nexus operation needs no declaration here:
the workflow makes the call, and that is the common case.

`php artisan durable:nexus-worker` drains the operations the cluster routes to this application.

### Three rejected settings {#three-settings-that-are-refused-rather-than-tolerated}

| Setting | Rejected | Reason |
|---|---|---|
| `lock.store: null` | always | it grants every lock, in every deployment |
| `lock.store: array` | under `illuminate` | a resume runs in a worker separate from whatever dispatched it, so two `array` locks never see each other: 15 overlapping critical sections out of 20, measured |
| the `sync` queue connection | under `illuminate` | it runs jobs inline, so a resume that dispatches another resume recurses until the stack ends |

`array` remains allowed under `memory`. It is Laravel's own testing default, and a test needs
mutual exclusion within one process only.

### Two behaviours that look like bugs {#two-things-that-read-like-bugs-and-are-not}

**The `sqlite` driver cannot host more than one worker.** With four workers popping the `jobs`
table, the queue returns `SQLSTATE[HY000]: General error: 5 database is locked`, and three of the
four die on their first job, with WAL enabled and a 60 s busy timeout. Use MySQL, PostgreSQL or
Redis for the queue as soon as you run a second worker.

**A killed worker's job stays reserved until `retry_after`**, 90 seconds by default. A worker
started with `--stop-when-empty` inside that window finds an empty queue and exits **without doing
anything**, which looks exactly like a failed resume. The resume has not received the job yet. A
supervised worker outlives the window, picks the job up, and the execution completes.

### Not in this package

**Temporal support is present.** `backend: 'temporal'` puts the journal and the run catalogue in
the cluster, and two workers drain what the application's own queue cannot carry:
`php artisan durable:temporal-worker` drains the workflow tasks, and
`php artisan durable:temporal-worker --role=activity` the activity tasks.

`gplanchat/durable-bridge-temporal` is only **suggested**. It installs eight packages,
five of them Symfony components that a Laravel application never loads, for some 36 MB. An
application that does not select the backend never installs them, and one that does gets an error
naming the package to install. Splitting the bridge, whose Symfony-coupled part is eight files out
of 774, would remove that weight; it is a separate change.

**No dashboard.** [`gplanchat/durable-filament`](#gplanchatdurable-filament--the-filament-dashboard)
requires this package. This package suggests it in `composer.json` and never requires or detects it.

---

## `gplanchat/durable-plugin`, the Sylius dashboard {#gplanchatdurable-plugin--the-sylius-dashboard}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-plugin
```

The plugin renders [the dashboard](../dashboard/) in the Sylius admin: an entry in the admin menu,
the run list on Tabler cards with cursor paging, and the run detail beside it. The panels, the
grouping and the wording come from `gplanchat/durable` itself, so a run reads the same here and on
the Magento screen. This package provides the Sylius chrome around them.

Labels show the human-readable `ActivityType.name` and fall back to technical IDs only when no
name is available.

The plugin **reads** runs and executes nothing. It requires `gplanchat/durable-bundle`, which wires
the run catalog it reads, so the command above is the whole install.

> [!NOTE]
> Live data comes from whichever backend is installed. The plugin requires no bridge:
> `gplanchat/durable` suggests the backend, once, for every integration. Without a backend, the
> plugin still installs, the route and the menu entry still work, and the dashboard renders its
> degraded state instead of live runs.

## `gplanchat/durable-filament`, the Filament dashboard {#gplanchatdurable-filament--the-filament-dashboard}

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

The plugin renders [the dashboard](../dashboard/) in a Filament 3 or 4 panel: a **Durable runs**
entry in the panel's navigation, the run list with cursor paging and the name and execution-id
filters that the backend can apply, and a page per run with its status, what it waits on, its
Nexus operations and its history. It is available in English and French.

The plugin **reads** runs and executes nothing. It requires `gplanchat/durable-laravel` and reads
the run catalog that package binds for its backend: in-memory, Illuminate or Temporal. Nothing in
the plugin names a backend, and nothing in `gplanchat/durable-laravel` names Filament.

> [!NOTE]
> The run page lists a run's Nexus operations when the catalog reports them, and only the Temporal
> catalog does: a journal cannot hold a Nexus operation, so on in-memory and Illuminate the section
> never appears.

## `gplanchat/durable-magento`, the Magento integration {#gplanchatdurable-magento--the-magento-integration}

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require gplanchat/durable-magento
```

A Magento 2.4 / Mage-OS module, listed as `Gplanchat_DurableModule` in `bin/magento module:status`.
It declares workflow and activity classes to the runtime, assembles the engine for a Magento
process, ships the workers as `bin/magento` commands, and adds a read-only admin screen under
**System > Durable processes > Process history**.

The screen uses Magento's chrome: a standard grid (paging, bookmarks, column controls, export, and
a multi-select status filter whose options come from the status enum itself), with the state of the
backend and the outcome counters above it. The content of the screen comes from neither Magento
nor this package; see [the dashboard](../dashboard/), which every host renders in its own chrome.

Magento's container has no equivalent of Symfony's tag autoconfiguration, so you declare classes
explicitly, in two arrays in `di.xml`:

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

You do not declare the *contract*. The factory reads each handler's interfaces and keeps those
carrying `#[AsActivityMethod]`, which leaves one declaration fewer to get wrong and keeps the
activity names those of the attributes.

Two more arguments of the same factory bound a run, and `di.xml` is the only place to set them:

```xml
<argument name="maxActivityRetries" xsi:type="number">3</argument>
<argument name="budgetSeconds" xsi:type="number">30</argument>
```

- `maxActivityRetries` is the retry ceiling of the activities that `MagentoRuntime::run()` runs in
  the calling process, the equivalent of the Symfony bundle's
  [`max_activity_retries`](../configuration/#max_activity_retries). The default, `0`, sets no cap.
  Temporal workers never read it: there, the cluster retries from the activity's own `RetryLimit`.
- `budgetSeconds` bounds `MagentoRuntime::run()`, which runs a workflow to its end inside the
  calling process. Past the budget, the call throws `WorkflowStuckException` instead of waiting
  longer. The default is `10`. The budget exists because of the retry ceiling: with no ceiling, an
  activity that keeps failing would keep that process busy forever. Workers and `workflowClient()`
  read neither argument.

**Magento supports two backends, and Composer enforces it.** Magento reaches in-memory and
Temporal, and the module declares a `conflict` on both SQL bridges, because
`Magento\Framework\App\ResourceConnection` is neither a Doctrine DBAL connection nor Illuminate's.
A DSN in `app/etc/env.php` selects the backend; no other setting does:

```php
'durable' => [
    'temporal' => ['dsn' => 'temporal://temporal:7233?namespace=default&tls=0'],
],
```

Without the DSN, the journal lives in the process that writes it and is lost when that process
ends. That is acceptable for a console command and unsuitable for anything else.

**Workers are `bin/magento` commands**, not queue consumers. Supervise them like any other
long-running process:

```bash
bin/magento durable:worker --role=journal   --time-limit=3600
bin/magento durable:worker --role=activity  --time-limit=3600
```

Each process serves one role on one queue. The two roles use two distinct Temporal queues, and you
tune their concurrency separately. Nothing goes through Magento's own `MessageQueue`: on Temporal,
an activity is a Temporal command and a resume is a workflow task, so a Magento topic would only
add a second queue for an operator to supervise.

**A missing worker shows differently depending on its role.** Without `--role=journal`, nothing
advances: executions start, their history fills, and no process answers their workflow tasks.
Without `--role=activity`, the execution appears to work, which makes it harder to notice: it
advances **up to its first activity** and stops there, with the order charged and the stock
untouched, and you find out from the customer. Running without the activity worker puts back the
failure this integration exists to remove.

The `--time-limit` and `--max-tasks` bounds serve the supervisor: they end the process so that the
supervisor can restart it. Retries belong to the cluster, which schedules an activity's attempts
whether or not a worker is listening. A run whose activity "failed after 3 attempts" within seconds
points to a missing worker, not to code that failed three times.

> [!WARNING]
> **Magento's own queue settings do not apply to Durable.** `retry_inprogress_after`, the
> `messagequeue_*` cron jobs and `queue_lock` carry nothing of Durable's, because nothing of
> Durable's goes through `MessageQueue`. Tune them for your own consumers.

> [!NOTE]
> Start executions **on the cluster**, outside the request that triggers them. An observer on
> `sales_order_place_after` that calls `RuntimeFactory::workflowClient()->startAsync()` hands the
> execution to Temporal and returns. `workflowClient()` needs the cluster, because `startAsync()`
> exists only on Temporal. An execution started inline would end with the request, which is the
> failure this integration exists to remove.

---

## Which do I install?

Each command below is the one the chooser on the [home page](/) gives you, written out in full.

The chooser reads its state from the URL, so a link can open the page with a situation already
selected, for example in an issue, a README or a support answer:

```
https://durable.rocks/?fw=magento&be=temporal#install
```

`fw` is the framework (`none`, `symfony`, `laravel`, `sylius`, `apiplatform`, `magento`), `be` is
where the state lives (`memory`, `temporal`, `dbal`, `illuminate`), and `dist` is the base
underneath a distribution (`none`, `symfony`, `laravel`). Each axis is optional. The chooser
ignores a value it cannot apply (a framework that has not shipped, a backend that the pairing
forbids) instead of forcing it, so an old link falls back to the default instead of showing a
combination that does not exist. Choosing in the page rewrites the address bar, so the link to
share is the one already in your address bar.

Each command in the table assumes that the project allows the beta line first:

```bash
composer config minimum-stability beta
composer config prefer-stable true
```

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
| Laravel, one SQL database | `composer require gplanchat/durable-laravel gplanchat/durable-bridge-illuminate` |
| Laravel, Temporal cluster | `composer require gplanchat/durable-laravel gplanchat/durable-bridge-temporal` |
| Magento, Temporal cluster | `composer require gplanchat/durable-magento gplanchat/durable-bridge-temporal` |

Each line names only the integration: the bundle pulls in the library, and the plugin pulls in the
bundle. Without a framework, you name the library yourself, and you also wire the workers yourself.

The Laravel lines name the integration and the bridge of the backend, because `gplanchat/durable-laravel`
requires neither bridge. It is a service provider that binds the four storage ports, with workflows
declared in `config/durable.php` and work on the queue the application already drains; the section
above lists what it does for you. To wire the stores yourself, require the library and the bridge
without the integration.

---

## Same behaviour on every backend {#one-codebase-one-behaviour}

Every backend runs the **same fiber driver** and the **same activity execution path**. A workflow
you tested in memory behaves the same way against DBAL or Temporal, including retry counting,
failure classification, cancellation and compensation.

When a capability has no equivalent on a backend, that backend **fails with an explicit message**.
[Backends](../backends/#capability-matrix) lists the
differences.

---

## Monorepo and releases

Durable is developed in a single repository, `gplanchat/durable-dev`. A split publishes each
package to its own read-only repository, so `composer require` pulls a small package instead of
the whole tree.
