# The four-application Nexus demonstration

Four applications, four Temporal namespaces, three frameworks. All four call, three serve.

| | `sylius/` — the shop | `symfony/` — the business | `magento/` — the Magento bench | `laravel/` — the logistics |
|---|---|---|---|---|
| namespace | `demo-shop` | `demo-business` | `demo-magento` | `demo-laravel` |
| serves | `stock` (`reserve`) | `billing` (`verify`, `charge`) | **nothing** | `delivery` (`schedule`, `ship`) |
| calls | `billing` | `stock` | all three services | `stock`, **from the workflow that serves** |
| what declares the handler | a tag under `when@demo` | `#[AsNexusServiceHandler]` | — | six lines of `config/durable.php` |
| profile that **serves** | `APP_ENV=demo` — DBAL journal, dashboard unchanged | `APP_ENV=dev` — Temporal journal | — | `temporal` backend |
| profile that **calls** | `APP_ENV=demo_caller` — Temporal journal | `APP_ENV=dev` | the DSN through `MAGENTO_DC_…` | the same one |
| PHP | 8.3 | 8.3 | **8.2** | **8.2** |

All four read the same contract package, `src/DurableDemoContracts/`. Nothing else travels between
them.

## What the fourth mockup adds

**Serving, outside the Symfony container.** The two original handlers were registered by
`NexusHandlerPass` — a compiler pass — and polled by a Messenger transport. One could conclude that
the serving half of Nexus **was** Symfony. The logistics serves it with two classes and six lines of
`config/durable.php`: `DeclaredNexusOperations` does the pass's work, `php artisan
durable:nexus-worker` the transport's.

**And it calls while it serves.** `ShipWorkflow` fulfils `delivery/ship`; before the goods leave, it
asks the shop for its verdict again through `stock/reserve`, on an endpoint that is not its own. One
execution therefore carries a served operation and a called operation — and its identifier is the
**operation's token**, not a name the application picked.

**And both serving hosts refuse the same mistake.** A fulfilling workflow whose required parameter
does not carry the contract's name makes the registration fail, on Symfony as on Laravel: the check
moved down into the core the day it had a second caller.

## What the third mockup adds

**It has none of what Nexus seemed to require.** The first two share the Symfony container, the
compiler pass that registers the handlers and the Messenger transport that runs the workers; a
reader could conclude that Nexus was a bundle feature. The Magento bench wires its services in
`di.xml`, runs its worker through `bin/magento durable:worker` and reads its DSN from
`app/etc/env.php` — and it calls both services without one line having been added to the core, to
the Temporal bridge or to `gplanchat/durable-magento`.

**Because calling requires nothing, and serving is wired once per host.**
`WorkflowEnvironment::nexusStub()` reads the contract by reflection, and the worker that advances the
execution is the same `WorkflowTaskRunner` in all three mockups. Serving, on the other hand, asks the
host to register handlers and to poll a Nexus queue: that is why Magento calls and does not serve.

**And the call order matters.** `OrderNexusWorkflow` asks first for everything that can say no —
verify the invoice, schedule the round, hold the stock — and only commits afterwards: charge, then
ship. Both reverse orders were written first, and measured: an order in USD held the stock before
having its invoice refused, and an order of six parcels was **charged** before the logistics refused
to carry it. None of the three contracts has an operation that gives back what it took; the call
order is the only compensation there is.

## What it shows, and a diagram does not

**Both shapes, side by side, written the same.** `OrderWorkflow` calls `verify` then `charge` on the
same stub. The first comes back in a few milliseconds, served by a method the business wrote; the
second takes some fifteen seconds, fulfilled by a workflow on the other side. The caller's code does
not tell the two apart, and that is the subject.

**The wait holds nothing open.** During one debugging session, the worker meant to advance the charge
stayed off for four minutes. The operation stayed in `NEXUS_OPERATION_STARTED`, the caller consumed
nothing, and everything finished normally when the worker came back. No connection, no process, no
transaction was waiting.

**A Nexus task is redelivered.** The `stock` handler writes its verdict into
`app_durable_stock_reservation`, keyed by order identifier. Replaying the same order returns the same
verdict and does not hold stock a second time.

**And that holds from any caller.** Repeated from Magento, with the facing worker off: 49 seconds in
`NexusOperationStarted`, then the nominal result when the worker came back.

## Prerequisites

**A Temporal server whose Nexus APIs are enabled.** `temporal server start-dev` will do.
`temporalio/auto-setup:1.25.2` — the image in the `compose.yaml` of `symfony/` — answers
`Nexus APIs are disabled` on endpoint creation: it is not enough as it stands.

**PHP 8.3 with `ext-grpc`.** Measured in §0.1 of the change: it is the only version that has it on
the reference machine, and it lacks `curl`, which `stripe/stripe-php` and the Chrome driver ask for
— two packages the demonstration does not run. Hence:

```bash
cd sylius && composer install --ignore-platform-req=ext-curl
```

**PHP 8.2 for the Magento and Laravel benches**, and it is the only version on the machine that has
`grpc`, `pdo_mysql`, `pdo_sqlite`, `curl`, `soap` and `intl` all at once — what Mage-OS and Laravel
require. The four mockups therefore run on two PHP binaries, and `demo/run.sh` takes `PHP` (default
`php8.3`), `PHP_MAGENTO` and `PHP_LARAVEL` (default `php8.2`).

**The Laravel bench installed.** It has neither a database to raise nor a cluster of its own: SQLite
is enough, and the demonstration's DSN comes in through the environment.

```bash
cd laravel && php8.2 composer install && php8.2 artisan migrate
```

**The Magento bench installed, its containers started, and its autoloader current.** The shared
contract comes in through an `autoload` entry of `magento/composer.json` — not through a path
repository as for the two others, because the bench's path repositories are `symlink: false` and
would copy the contract. After a `git pull` that touches `magento/composer.json`:

```bash
cd magento && php8.2 composer dump-autoload
docker compose up -d magento-db redis
```

Its DSN does not need changing: `demo/run.sh` passes the demonstration's own through
`MAGENTO_DC_DURABLE__TEMPORAL__DSN`, Magento's convention for overriding `app/etc/env.php` from the
environment. The cluster in the bench's `compose.yaml` — `temporalio/auto-setup:1.25.2` — will not
do: its Nexus APIs are disabled.

**A database for the shop.** PHP 8.3 has no `pdo_mysql` on this machine, but it has `pdo_pgsql`:

```bash
docker run -d --name durable-demo-pg \
  -e POSTGRES_USER=sylius -e POSTGRES_PASSWORD=sylius -e POSTGRES_DB=sylius_demo \
  -p 55432:5432 postgres:16-alpine

cd sylius
export DATABASE_URL='pgsql://sylius:sylius@127.0.0.1:55432/sylius_demo?serverVersion=16&charset=utf8'
bin/console doctrine:schema:update --force --complete
```

Then two variants with stock, so the shop has something to hold:

```sql
INSERT INTO sylius_product (id, code, created_at, enabled, variant_selection_method, average_rating)
VALUES (1, 'MUG', NOW(), true, 'choice', 0);
INSERT INTO sylius_product_variant
  (id, product_id, code, created_at, position, enabled, version, on_hold, on_hand, tracked, shipping_required, recurring)
VALUES (1, 1, 'MUG_BLUE', NOW(), 0, true, 1, 0, 5, true, true, false),
       (2, 1, 'MUG_RED',  NOW(), 1, true, 1, 0, 1, true, true, false);
```

## Running it

```bash
temporal server start-dev --port 7239 --ui-port 8239     # if you have no cluster yet

TEMPORAL_ADDRESS=127.0.0.1:7239 bin/demo-nexus           # namespaces + endpoints
TEMPORAL_ADDRESS=127.0.0.1:7239 demo/run.sh              # the workers
```

`bin/demo-nexus` and `demo/run.sh` both print the call commands with the right values.
`demo/run.sh --status` says who is running, `--stop` shuts everything down.

### If you reset the stock, restart the shop's worker

The shop's Nexus worker is a **long-running** process: its `EntityManager` keeps the
`ProductVariant` entities in its identity map, and an `UPDATE … SET on_hold = 0` run in SQL under its
feet is invisible to it — it then writes the old value back, increased. Two inconsistent readings
came out of it while this bench was being set up, `on_hold` at 4 and at 5 for orders of 2 and of 1.
After restarting the worker, the delta is exactly the order's.

### The three endpoints are not test residue

`demo-shop-stock`, `demo-business-billing` and `demo-laravel-delivery` are **stable**. The
integration suite creates others on the same cluster, named `durable-sv-…`, and deletes them at the
end of every test: those are the ephemeral ones. A cleanup `nexus endpoint delete` must not take the
`demo-*` ones with it — without them the caller leaves and the server does not know where to route,
which gives a failure naming neither the contract nor the handler.

## Eight processes, and not twelve

The count follows what each mockup actually has to drain, not a rule of three workers per
application.

| process | mockup | profile | what it does |
|---|---|---|---|
| `shop-serves-stock` | `sylius/` | `demo` | polls the Nexus tasks of `demo-shop` |
| `shop-workflows` | `sylius/` | `demo_caller` | advances `OrderWorkflow` |
| `business-serves-billing` | `symfony/` | `dev` | polls the Nexus tasks of `demo-business` |
| `business-workflows` | `symfony/` | `dev` | `ReserveStockWorkflow`, `ChargeWorkflow` |
| `business-activities` | `symfony/` | `dev` | the payment activity |
| `magento-workflows` | `magento/` | — | advances `OrderNexusWorkflow` |
| `logistics-serves-delivery` | `laravel/` | — | polls the Nexus tasks of `demo-laravel` |
| `logistics-workflows` | `laravel/` | — | advances `ShipWorkflow` |

**No activity worker for the shop, for Magento or for the logistics**, and for the same reason in all
three cases: their workflows have no activity — what they wait for, they wait for from a timer or
from an operation served elsewhere. One more worker would do nothing but poll an empty queue, and the
demonstration would lie about what it asks for.

**No Nexus worker for Magento**: it serves nothing, so there is nothing to poll. It is also why it
has no endpoint — four namespaces, three endpoints.

## Why the shop has two profiles, and why `dev` is not one of them

Serving and calling do not fit in the same Durable configuration, and that is not a workaround.

A Nexus call leaves from a workflow, and a workflow can only schedule an operation if its journal is
the cluster: `EventStoreCommandBuffer` refuses while saying so, because an SQL journal has no server
to address the scheduling to. And the profile that **serves** keeps its DBAL journal, since that is
what the shop's dashboard reads.

Two profiles, then, and that is what a real deployment looks like: the process that renders the
dashboard and the one that executes the workflows are two deployments of the same code.

Neither of them is `dev`, and that too has a measured reason. A `temporal://` Messenger transport
declared with no DSN makes `doctrine:schema:create` fail: Doctrine's schema listener walks **every**
transport, and the message that comes out is "Invalid temporal:// DSN", far from Nexus and far from
Messenger. An environment with no cluster therefore has no DSN, no transport and no handler — and
`dev`, `prod` and `test` stay exactly what they were.
