# gplanchat/durable-laravel

Durable execution in a Laravel application: one published config file binds the four storage ports,
and the workflow code is the one that already runs on Symfony.

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
'lock' => ['store' => null, 'ttl' => 300, 'wait' => 10],
```

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
- **Temporal.** `gplanchat/durable-bridge-temporal` requires `symfony/messenger`,
  `symfony/dependency-injection`, `symfony/http-kernel` and `symfony/config`, to reach a gRPC client
  that needs none of them. Serving it from here is not decided, and this package refuses the
  combination by name until it is.

MIT. See [`LICENSE`](LICENSE).
