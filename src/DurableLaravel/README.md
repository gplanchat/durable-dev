# gplanchat/durable-laravel

Durable execution in a Laravel application: one published config file binds the four storage ports,
and the workflow code is the one that already runs on Symfony.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **The tests are in the monorepo, not here.** This split carries source only. What covers it is
> `tests/unit/DurableLaravel/` in the monorepo, run by its `laravel` suite.
>
> **Documentation**: [durable.rocks](https://durable.rocks).

```bash
composer require gplanchat/durable-laravel gplanchat/durable-bridge-illuminate   # one SQL database
php artisan migrate
```

On a Temporal cluster, require `gplanchat/durable-bridge-temporal` instead of the Illuminate bridge
and set `backend` to `temporal`. This package suggests both bridges and requires neither: you install
the one of the backend you select. With `backend` set to `illuminate`, the default, and the bridge
missing, registration fails with the command to run.

Package auto-discovery registers the provider. `migrate` creates the four tables, because
`gplanchat/durable-bridge-illuminate` ships them.

## What it is not

**A durable engine for Laravel.** That square is occupied — `durable-workflow/workflow` runs on
Laravel queues with its own storage, writes workflows as straight-line methods on Fibers since 2.0,
and can move to its own server or Cloud, with PHP, Python and Rust SDKs. It is good at it. If that
is what you want, take it.

What this package sells is a **different backend choice**: the same workflow code against a Temporal
cluster (Nexus included) or against a single SQL database, and a mixed Symfony /
Sylius / Laravel estate sharing a single engine.

## Configuration

```bash
php artisan vendor:publish --tag=durable-config
```

```php
// config/durable.php
'backend' => 'illuminate',   // or 'temporal', or 'memory'
'connection' => 'durable',   // a connection of its own, see below
'workflows' => [App\Workflows\Onboarding::class],
'activity_handlers' => [App\Activities\OnboardingActivities::class],
'lock' => ['store' => null, 'ttl' => 300, 'wait' => 10],
```

```php
// config/database.php — the journal on a connection of its own
'connections' => [
    // …the application's…
    'durable' => [
        'driver' => 'pgsql',
        'url' => env('DURABLE_DB_URL'),
    ],
],
```

Give the journal a connection of its own. `null` takes the application's default one, which is
strongly discouraged (**DUR054**): Durable's transactions then nest inside business ones. The
shipped migrations follow `connection`, so `php artisan migrate` creates the tables there. At boot,
in the console, a journal on the default connection is warned about in the log. Better still, point
the `durable` connection at a database and a database user of Durable's own, so that business code
cannot reach the journal's tables at all.

On the `temporal` backend, `temporal.guzzle_client` names a container binding whose
`GuzzleHttp\ClientInterface` carries gRPC when the DSN says `transport=guzzle` — Laravel already
ships Guzzle 7.14 or newer. `null` builds a default client. Likewise `temporal.psr18_client` and
`temporal.psr17_factory` hand a PSR-18 client to `transport=http`, the JSON gateway, instead of curl.

### Starting a run

`WorkflowResumeDispatcher::dispatchNewWorkflowRun()` starts a run on every backend:

- on `illuminate`, it queues the first resume for `queue:work`;
- on `temporal`, it starts the workflow on the cluster, which delivers everything after that;
- on `memory`, it drives the run **in the caller's process**: the call returns once the run has
  completed, or once it waits on a signal or on something due later than the ten-second drain
  budget. The journal of this backend lives in the process, so nothing else could advance it.

### Workflows are declared, not scanned

Laravel's container has no equivalent of Symfony's attribute autoconfiguration, so the `workflows`
key names the classes. **What that does not change is the class**: one written for
`gplanchat/durable-bundle` runs here unmodified, and resolves both by the name its `#[AsWorkflow]`
attribute declares and by its FQCN.

The `activity_handlers` key does the same for activities. Each class serves the contract its
`#[AsActivityHandler]` names, or else every interface it implements whose methods carry
`#[AsActivityMethod]`. It is resolved from the container each time one of its activities runs; bind it as a singleton to
share one instance. A class
that does not exist, serves no activity, or lacks a method of its contract is refused at boot.

The list is also the cheap answer. Measured on a thousand classes: naming them costs 0,14 ms and
does not grow with the application, while a reflection scan costs 15 ms **and loads all thousand
into every process** to find five. There is no `durable:cache` for the same reason — a cached
manifest beats the list by 0,11 ms, and `config:cache` already caches the file.

A resume for a type nobody declared fails naming the type, the config key, and what *is* declared.
`WorkflowRegistry` alone would say `Unknown workflow type: X` and stop, because the core has never
heard of a `config/durable.php`.

**One choice of backend binds all four ports.** A journal on one backend and metadata on another is
not a configuration, it is a fault, so the choice is a single value and a backend this package does
not serve is refused **by name** at registration rather than at the first execution.

`illuminate` puts the journal in a SQL database through Laravel's database layer. Name a connection
of its own in `connection`, not the application's default one (**DUR054**): on a shared connection,
Durable's transactions nest inside the application's, and a business rollback erases journal
events.

### The lock store is the one setting that can silently corrupt a run

`lock.store` must lock **across processes**. Measured on Laravel 12, four workers, twenty resumes of
one execution:

| store | overlapping critical sections |
|---|---|
| `database`, `file` | 0 of 20 |
| `array` | 15 of 20 — excludes inside one process only |
| `null` | 15 of 20 — excludes nothing |

All four implement `LockProvider`, so **the type system does not protect you here**. Two workers
replaying one execution both believe they are discovering the commands it produces, and those
commands go out twice.

`null` is therefore refused **at boot**: no deployment needs a lock that grants everything. `array`
is not, because it is Laravel's own default cache in the testing environment and excluding inside
one process is exactly what a test wants — it is the worker command's business to refuse it, since
that is where the plurality of processes lives.

## One resume at a time

`Queue\ResumeLock` is the one thing no storage choice can supply. Two workers resuming the **same**
execution both replay it, both believe they are discovering the commands it produces, and those
commands go out twice. The journal does not prevent it — it faithfully records whatever it is
handed, twice included.

```php
$lock = new ResumeLock($cacheStore);          // any store implementing LockProvider
$lock->around($executionId, fn() => $runner->resume($executionId));
```

**It waits on its own rather than calling `Lock::block()`**, and that is deliberate: `block()` calls
a **global** `now()`, which only a full Laravel application defines — `illuminate/support` publishes
it under its own namespace only. A package that relies on it works inside an application and breaks
in a standalone worker or a test, which is the worst of both: the failure only happens where nobody
is looking.

**`LockProvider` is the only contract this lock needs, and it filters nothing.** An earlier version
of this file claimed it forced the caller to pick a store that can actually lock, and that the
`file` store did not implement it. **Both halves are wrong on Laravel 12**, and measuring said so:
nine stores implement `LockProvider`, `file` among them — and it locks correctly across processes —
while `NullStore` implements it too and its `NoLock::acquire()` returns `true` unconditionally.

Measured, twenty resumes of one execution across four `queue:work` processes:

| store | overlapping critical sections | max concurrency |
|---|---|---|
| `database` | 0 | 1 |
| `file` | 0 | 1 |
| `array` | **15 of 20** | **4** |
| `null` | **15 of 20** | **4** |

`array` excludes inside one process and not between two; `null` excludes nothing at all and says so
to nobody. Both type-check. **So the type is not the guard — the choice is yours, and it is the one
choice in this package that silently forks a journal when it is wrong.** Use `database`, `redis`,
`memcached`, `dynamodb` or `file`.

## Not in this package

- **A Filament dashboard.** `gplanchat/durable-filament` requires this package, and this package
  never requires, suggests or detects Filament. A Laravel application without Filament hears
  nothing about it — the same one-directional shape as `durable-plugin` against `durable-bundle`.
The `temporal` backend used to be on this list, and it no longer is: `backend => 'temporal'` binds
the journal and the catalogue to a cluster, `durable:temporal-worker` drains the workflow tasks,
`durable:temporal-worker --role=activity` the activity tasks, and `durable:nexus-worker` serves the Nexus operations
`durable.nexus.handlers` declares. `gplanchat/durable-bridge-temporal` stays **suggested and not
required** — it pulls in four Symfony components a Laravel application never loads, and an
application on the `illuminate` backend has no use for them.

⚠ **Run the activity worker too.** Without `--role=activity`, nothing takes the tasks a workflow
schedules on the cluster's activity queue: a run advances up to its first activity and stops there.

⚠ **The two worker commands are registered by the `temporal` backend only.** On `illuminate` or
`memory`, `artisan list` shows neither, and the error you get from calling one names the command,
not the backend that would have provided it.

⚠ **A fulfilling workflow's parameter names are checked, and the check refuses.** If a required
parameter of a workflow claiming an operation matches nothing in the contract's signature,
registration fails and names both signatures. The payload is keyed by parameter name at both ends,
so without that refusal the parameter would receive `null`, with no error and no trace. The check is
`Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames`, in the core: Symfony calls it from
its compile pass, this package from `durable.nexus.handlers`.

MIT. See [`LICENSE`](LICENSE).
