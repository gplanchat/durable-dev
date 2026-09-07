# The Magento bench

A **bench**, not an application: enough to run `gplanchat/durable-magento` inside a real Magento and
watch what it does. A Tier 1 module is not tested against anything smaller than Magento, so this
directory *is* the harness.

What is in the repository: `composer.json` and its lock, `compose.yaml`, the extension pre-flight
script, two probes, and the probe module they drive. Nothing of the distribution — see
`.gitignore`, which explains why the rule is inverted there.

## What is needed before starting

Mage-OS installs without Adobe credentials, from `https://repo.mage-os.org/`. The bench is pinned on
`mage-os/product-community-edition:2.2.0`, compatible with PHP 8.2, which Durable's dependency graph
imposes.

```bash
cd magento
bash ./check-php-extensions.sh
```

The script measures; the list it carries is more reliable than a README's. On Debian or Ubuntu,
`ext-pdo_mysql` is added by `sudo apt-get install -y php8.2-mysql`.

## Bootstrapping

```bash
cd magento
cp .env.example .env
docker compose up -d
composer install
```

The ports are **shifted on purpose**, so as not to fight over those of the neighbouring benches:

| service | host |
|---|---|
| MySQL | `33306` |
| OpenSearch | `9201` |
| Temporal | `7234` |
| Temporal UI | `8088` |
| the shop and its admin | `8080` |

Then the installation, if the database is empty:

```bash
bin/magento setup:install \
  --base-url=http://127.0.0.1:8080/ \
  --db-host=127.0.0.1:33306 --db-name=magento --db-user=magento --db-password=magento \
  --admin-firstname=Durable --admin-lastname=Ops --admin-email=durable@example.com \
  --admin-user=durable --admin-password='<your password>' \
  --language=en_US --currency=USD --timezone=UTC --use-rewrites=1 \
  --search-engine=opensearch --opensearch-host=127.0.0.1 --opensearch-port=9201 \
  --backend-frontname=admin

bin/magento module:enable Gplanchat_DurableModule
bin/magento setup:upgrade
bin/magento cache:flush
```

The module is called **`Gplanchat_DurableModule`** and its Composer package
**`gplanchat/durable-magento`** — the two conventions do not cross, and the
module's `registration.php` explains why.

The bench enables a second one, **`Gplanchat_DurableProbe`**, which lives in
`app/code`. It is the one that carries the demonstration and the probes: the
published package declares **no** workflow, and its two `di.xml` arrays are
empty. An integration module has no business making a project carry workflows
that are not its own.

```bash
bin/magento module:enable Gplanchat_DurableModule Gplanchat_DurableProbe
```

## Seeing it run

**On the command line**, the shortest path:

```bash
bin/magento durable:demo ORD-4242
#   1. durable.demo.charge
#   2. durable.demo.reserve
#   3. durable.demo.notify
#   → 'notify:charge:ORD-4242'
```

The three lines are not labels written into the command: they are the names `#[ActivityMethod]`
carries on the contract, resolved at the moment the module assembles its engine.

**And Magento knows how to call the repository's two other mockups**, through Nexus, on a third
namespace:

```bash
MAGENTO_DC_DURABLE__TEMPORAL__DSN='temporal://127.0.0.1:7239?namespace=demo-magento&tls=0' \
  bin/magento durable:demo:nexus MAG-1 1200 MUG_BLUE=1
```

`OrderNexusWorkflow` has the invoice verified by the Symfony mockup, the stock held by the Sylius
mockup, then charges — the charge being fulfilled by a workflow on the other side, which takes some
fifteen seconds. The bench **serves** no operation: calling asks nothing of the host, serving would
ask for a handler registry and a Nexus queue, which do not exist here.

It does not run on its own: the five other workers, the two endpoints and the prerequisites are in
[`demo/README.md`](../demo/README.md). The cluster of the `compose.yaml` above does not fit — its
Nexus APIs are disabled.

**In the back office** — Magento ships its own development server, there is nothing to
install:

```bash
php -S 127.0.0.1:8080 -t pub/ phpserver/router.php
```

Then `http://127.0.0.1:8080/admin`, and **`System > Durable processes > Process history`**. The
screen is read-only: what an operator comes there for is to know whether an order went through, not
to restart it by hand — resuming from a browser would bypass the per-execution lock.

An administration account is added by `bin/magento admin:user:create`. If two-factor authentication
gets in the way locally: `bin/magento module:disable Magento_TwoFactorAuth`.

## Where the journal lives

Magento reaches only two backends, **and that is final**: `memory` and `temporal`. The SQL bridges
are refused at installation by a `conflict` in the module's `composer.json`, because
`ResourceConnection` is neither a Doctrine DBAL connection nor an Illuminate one.

It is not a backend name that chooses, it is **the presence of a DSN** in `app/etc/env.php`, next
to `lock` and `queue`:

```php
'durable' => [
    'temporal' => ['dsn' => 'temporal://127.0.0.1:7234?namespace=default&tls=0'],
],
```

Without it, the journal lives in the process that writes it and dies with it — the back-office grid
is then empty, **and that is the right answer**: an administration request opens a fresh process.
The page says so itself rather than letting one believe in a failure.

With it, the grid reads the cluster:

```
Run                                   | Workflow       | Status  | Started
d81bfb25-af86-43b9-a310-9d9d34695a30  | DurableJournal | running | 2026-08-28 09:23:45
```

⚠ **Two caveats to know.** The name displayed is `DurableJournal`: it is the Temporal type that
*carries* an execution's journal, not the business type. And the status stays `running` — **no
worker drains the task queue yet**, so nothing closes the journals. That is the sequel to task 5 of
the `magento-module` change.

## The probes

Two scripts, kept because they replay, and a probe module in `app/code` that carries the queue topic
they drive — outside the published package, because a topic whose handler does nothing but sleep has
no business being in it.

```bash
php probe-lock.php which                   # is the lock shared across processes?
php probe-queue.php publish <label> <s>    # a message that lingers
php probe-queue.php state                  # the state of the messages, in plain words
php probe-queue.php recover                # the cron task that catches up the IN_PROGRESS
php probe-queue.php unlock                 # the cron task that empties queue_lock
php probe-queue.php purge                  # sets aside the messages of past campaigns
```

⚠ **Measuring on a dirty queue answers beside the point**: a consumer takes the oldest candidate,
not yours. `purge` before any campaign.

## What bites, and what nothing tells you

Six host constraints found while building. Each one cost a round of debugging, and none of them
shows up where it is committed.

- **Magento forbids `final`** on any class its container instantiates: it generates an
  `Interceptor` that extends it. The message — *"cannot extend final class"* — does not say that the
  keyword is the cause.
- **Mage-OS audits path repositories.** `composer-dependency-version-audit-plugin` refuses a package
  resolved locally when a more recent one exists on packagist.org. The bench disables it for
  itself; a consuming project must keep it.
- **A module in `app/code` does not autoload itself** on this distribution: Mage-OS's root
  `composer.json` does not carry the `psr-0: {"": ["app/code/"]}` of a classic Magento. Registering
  a component and autoloading its classes are two distinct mechanisms, and only the first is
  automatic.
- **A controller is resolved by convention from the module name**, not from autoloading:
  `Gplanchat_DurableModule` + `\Controller\Adminhtml\…`. The module therefore adds a second `psr-4` entry
  for that one directory. Without it, the route is declared, **the menu shows**, and Magento serves
  its 404 in the admin chrome — every symptom points at the declaration, which is right.
- **An optional constructor argument is not autowired**: Magento takes its default. It has to be
  named in `di.xml`, otherwise the dependency stays `null` without a line of error.
- **Renaming a class that the container instantiates** leaves a stale interceptor in
  `generated/code/`, which `setup:upgrade --keep-generated` does not remove. Symptom: *There are no
  commands defined in the "durable" namespace*.

## When the module changes and the bench does not follow

The path repositories are `"symlink": false`: editing `src/DurableModule` changes **nothing** in
`magento/vendor/gplanchat/durable-magento` until it has been reinstalled.

```bash
composer update gplanchat/durable-magento gplanchat/durable-bridge-temporal
rm -rf generated/code/Gplanchat
bin/magento setup:upgrade
bin/magento cache:flush
```

Composer reads the path package again from the repository's **main copy**, not from a worktree: a
change made in a worktree will only reach the bench once it has been merged.

## Other bootstrap failures

- **`Class "Magento\Setup\Mvc\Bootstrap\InitParamListener" not found`** — `composer dump-autoload`
  in `magento/`; the overlay declares `Magento\Setup\` in its `composer.json`.
- **`You do not have the SUPER privilege … CREATE TRIGGER`** — the overlay starts MySQL with
  `--log-bin-trust-function-creators=1`; recreate the service:
  `docker compose up -d --force-recreate magento-db`.
- **`Could not validate a connection to the OpenSearch`** — `docker compose ps opensearch`, and
  check port `9201`.

## Notes

- This repository does not ship a preinstalled Magento distribution.
- The bench is Mage-OS. Whether the module runs unmodified on Adobe's distribution is an open
  question, which nobody has measured.
