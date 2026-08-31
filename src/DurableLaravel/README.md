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
composer require gplanchat/durable-laravel
php artisan migrate
```

Package auto-discovery registers the provider. `migrate` creates the four tables, because
`gplanchat/durable-bridge-illuminate` ships them and this package requires it.

## What it is not

**A durable engine for Laravel.** That square is occupied — `durable-workflow/workflow` does
`yield`-as-checkpoint on Laravel queues, has its own storage, needs no server, and is good at it. If
that is what you want, take it.

What this package sells is the **backend choice**: the same workflow code against a Temporal cluster
or against one SQL database, and a mixed Symfony / Sylius / Laravel estate sharing a single engine.

## Configuration

```bash
php artisan vendor:publish --tag=durable-config
```

```php
// config/durable.php
'backend' => 'illuminate',   // or 'memory'
'connection' => null,        // the application's default
'workflows' => [App\Workflows\Onboarding::class],
'lock' => ['store' => null, 'ttl' => 300, 'wait' => 10],
```

### Workflows are declared, not scanned

Laravel's container has no equivalent of Symfony's attribute autoconfiguration, so the `workflows`
key names the classes. **What that does not change is the class**: one written for
`gplanchat/durable-bundle` runs here unmodified, and resolves both by the name its `#[AsWorkflow]`
attribute declares and by its FQCN.

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

`illuminate` puts the journal on the connection the application already owns. That is the whole
point of **DUR030**: the journal append and the business write land in one transaction because they
are the same connection.

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

## Not in this package

- **A Filament dashboard.** `gplanchat/durable-filament` will require this package, and this package
  will never require, suggest or detect Filament. A Laravel application without Filament hears
  nothing about it — the same one-directional shape as `durable-plugin` against `durable-bundle`.
The `temporal` backend used to be on this list, and it no longer is: `backend => 'temporal'` binds
the journal and the catalogue to a cluster, and `durable:nexus-worker` serves the Nexus operations
`durable.nexus.handlers` declares. `gplanchat/durable-bridge-temporal` stays **suggested and not
required** — it pulls in four Symfony components a Laravel application never loads, and an
application on the `illuminate` backend has no use for them.

⚠ **The two worker commands are registered by the `temporal` backend only.** On `illuminate` or
`memory`, `artisan list` shows neither, and the error you get from calling one names the command,
not the backend that would have provided it.

⚠ **What Symfony checks and this package does not.** `NexusHandlerPass` refuses the container when a
fulfilling workflow's parameter name diverges from the contract it claims. Reading a config file
cannot do the same work: `durable.nexus.handlers` registers, it does not compare. A parameter
renamed on one side only hands the workflow `null`, with no error and no trace.

MIT. See [`LICENSE`](LICENSE).
