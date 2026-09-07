# The Laravel mockup

An ordinary Laravel 12 application — `composer create-project laravel/laravel` — that **serves** a
Nexus service to the four-application demonstration.

What counts is what it serves. The three other mockups proved that calling asks nothing of the
host; this one is the first to show the other half **outside Symfony's container**: a handler
declared by `config/durable.php`, a workflow that fulfils an operation, and a Nexus worker started
by `php artisan`.

| | |
|---|---|
| namespace | `demo-laravel` |
| serves | `delivery` — `schedule` (right away), `ship` (through a workflow) |
| calls | `stock`, from the workflow that fulfils `ship` |
| backend | `temporal` — serving Nexus requires it, it is the cluster that routes |
| PHP | 8.2, the only version on this machine that has `grpc` **and** `pdo_sqlite` |

## What had to be written, and what did not

Two classes, and **six lines of configuration**:

```php
// config/durable.php
'backend' => env('DURABLE_BACKEND', 'temporal'),
'temporal' => ['dsn' => env('DURABLE_DSN')],
'workflows' => [App\Durable\Workflow\ShipWorkflow::class],
'nexus' => ['handlers' => [
    App\Durable\Nexus\DeliveryHandler::class => DeliveryContract::class,
]],
```

Nothing else: no provider to write, no command, no compiler pass.
`gplanchat/durable-laravel` brings `durable:nexus-worker` and `durable:temporal-worker`, and
`DeclaredNexusOperations` does the work `NexusHandlerPass` does on the Symfony side — read the
contract, hold between the handler's signature and what the registry calls.

And what it refuses, it refuses the way Symfony does: a workflow whose mandatory parameter does not
carry the name declared by the contract fails registration by naming both signatures. The check
lives in the core — `NexusFulfilmentParameterNames` — and both serving hosts call it at the same
moment. It was written for Symfony and only stayed there until a second host came along.

## The workflow that serves **and** calls

`ShipWorkflow` fulfils `delivery/ship`, waits six seconds of warehouse preparation,
then **calls `stock/reserve` at the Sylius shop** before releasing the goods. One and the same
execution therefore carries a served Nexus operation and a called Nexus operation, in the same
journal.

The call is riskless because `reserve` is idempotent per order identifier: the shop reads back the
decision taken at order time instead of taking a new one — which is why the lines passed in are
empty.

## Running it

The bench has no cluster of its own, and no database to install: SQLite is enough, and the
demonstration's DSN comes in through the environment.

```bash
cd laravel
php8.2 composer install
php8.2 artisan migrate            # sqlite: the cache table carries the idempotence of schedule

DURABLE_DSN='temporal://127.0.0.1:7239?namespace=demo-laravel&nexus_task_queue=demo-laravel-nexus&tls=0' \
  php8.2 artisan durable:nexus-worker      # polls Nexus tasks
DURABLE_DSN='…' php8.2 artisan durable:temporal-worker  # drives ShipWorkflow forward
```

`demo/run.sh` starts both with the right values, at the same time as the six other processes. The
prerequisites for the whole thing are in [`demo/README.md`](../demo/README.md).

## The probe

```bash
DURABLE_DSN='temporal://127.0.0.1:7999?namespace=probe&nexus_task_queue=q&tls=0' php8.2 probe-nexus.php
```

It boots the application, takes the core's registry out of the container and **dispatches both
operations** — the very method the Nexus worker calls when a task arrives. No cluster, no endpoint,
no process on the other side: the DSN designates a closed port, and nothing connects to it. This is
what CI runs on every commit; the end-to-end, for its part, lives in `demo/`.

## What it is not

**A dashboard.** `gplanchat/durable-filament` will carry one; this bench has no interface, and its
`welcome.blade.php` is Laravel's.

**A business application.** It does not model a logistics operation: it serves a demonstration
contract, and its handler picks a slot by a three-line rule.
