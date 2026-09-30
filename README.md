# Durable (PHP)

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

Durable runs long-running processes in PHP. You write a process as one PHP method, waits included,
and it survives crashes and deploys. Durable records each step in a journal (the history of a run's
steps and their results). After a restart, a worker (the process that runs workflows and activities)
replays the method from its first line: a step already in the journal returns its recorded result
instead of running again, and the method resumes at the first step the journal does not have.
Because the method runs again from its first line, it has to take the same decisions on every run:
HTTP calls, database queries, random numbers and timestamps belong in activities.

It runs on Symfony, Sylius, Laravel, Magento 2.4 and Mage-OS. On Symfony and Laravel, work goes
through Messenger or the application's queue; on Magento, through `bin/magento durable:worker`. The
same workflow code runs on three backends (where the journal lives): in memory (tests), on one SQL
database (Doctrine DBAL or Laravel's database layer), or on a Temporal cluster. To switch, you install
that backend's bridge and change one configuration value. Magento offers memory and Temporal only.

## Example: a workflow that waits three days

An order is charged, the workflow waits three days, then asks for a review. On a SQL database or on
Temporal, a deploy or a crash during the wait loses nothing. The journal holds the charge, and the
timer still fires after 72 hours.

```php
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'order-follow-up')]
final class OrderFollowUp
{
    /** @param ActivityStub<OrderActivities> $orders */
    #[AsWorkflowMethod]
    public function run(
        string $orderId,
        #[Activities(OrderActivities::class)]
        ActivityStub $orders,
        WorkflowEnvironment $env,
    ): void {
        $env->await($orders->charge($orderId));            // retried with backoff, without limit by default
        $env->sleep(Duration::hours(72));                  // a timer, not a scheduled job
        $env->await($orders->sendReviewRequest($orderId));
    }
}
```

`OrderActivities` is an interface whose methods are activities (calls with a side effect, such as an
HTTP request or an e-mail). [Getting started](documentation/user/getting-started/_index.md) builds a
complete workflow on Symfony: the activity interface and its handler, the controller that starts it,
and the worker that runs it.

## Install

Durable is on its beta line, and each package requires its siblings from the same line. Allow beta
releases first, then require the package for your application:

```bash
composer config minimum-stability beta
composer config prefer-stable true
```

| Your application | Command |
|---|---|
| Symfony, one SQL database | `composer require gplanchat/durable-bundle gplanchat/durable-bridge-dbal` |
| Symfony, Temporal cluster | `composer require gplanchat/durable-bundle gplanchat/durable-bridge-temporal` |
| Sylius | `composer require gplanchat/durable-plugin gplanchat/durable-bridge-dbal` |
| Laravel, one SQL database | `composer require gplanchat/durable-laravel`, then `php artisan migrate` and `php artisan vendor:publish --tag=durable-config` |
| Laravel, Temporal cluster | `composer require gplanchat/durable-laravel gplanchat/durable-bridge-temporal`, publish the config, then set `backend` to `temporal` and fill the `temporal` DSN in `config/durable.php` |
| Magento 2.4 or Mage-OS, Temporal cluster | `composer require gplanchat/durable-magento gplanchat/durable-bridge-temporal` |
| No framework, or unit tests only | `composer require gplanchat/durable` |

On Symfony and Sylius, add `composer config extra.symfony.allow-contrib true` before the `require`,
so Flex registers the bundle. The Symfony SQL database line also needs DoctrineBundle (with
`doctrine/orm`) and the Doctrine Messenger transport, which
[Getting started](documentation/user/getting-started/_index.md) lists. On Laravel, the Illuminate
bridge (the SQL stores) installs with `durable-laravel` whichever backend you select.

The [Packages](documentation/user/packages/_index.md) page has the full table of combinations and
what each package adds. PHP 8.2 or later is required. On Temporal, the bridge uses `ext-grpc` when it
is loaded and falls back to `ext-curl` with HTTP/2 otherwise; the
[bridge README](src/Bridge/Temporal/README.md) has the details.

## Packages in this repository

This monorepo holds every package. A split publishes each one to its own read-only repository, so
`composer require` pulls only what you name.

| Package | Path | Role |
|--------|------|------|
| `gplanchat/durable` | [`src/Durable/`](src/Durable/) | Core library (workflows, activities, event store, in-memory and integration surfaces) |
| `gplanchat/durable-bundle` | [`src/DurableBundle/`](src/DurableBundle/) | Symfony bundle (Messenger, configuration, profiler) |
| `gplanchat/durable-bridge-temporal` | [`src/Bridge/Temporal/`](src/Bridge/Temporal/) | Temporal gRPC bridge (no official Temporal PHP SDK; see **DUR006**) |
| `gplanchat/durable-bridge-dbal` | [`src/Bridge/Dbal/`](src/Bridge/Dbal/) | Doctrine DBAL journal and stores: durable execution on one SQL database, no cluster (**DUR030**) |
| `gplanchat/durable-bridge-illuminate` | [`src/Bridge/Illuminate/`](src/Bridge/Illuminate/) | Illuminate (Laravel) journal and stores: durable execution on one SQL database through Laravel's database layer (**DUR030**) |
| `gplanchat/durable-laravel` | [`src/DurableLaravel/`](src/DurableLaravel/) | Laravel integration: binds the four storage ports from one published config file, work rides the application's queue |
| `gplanchat/durable-filament` | [`src/DurableFilament/`](src/DurableFilament/) | Filament 3 and 4 panel plugin: read-only dashboard of workflow runs, requires the Laravel integration |
| `gplanchat/durable-magento` | [`src/DurableModule/`](src/DurableModule/) | Magento 2 / Mage-OS module: `durable:worker`, a read-only admin grid and process history, memory and Temporal backends (**DUR046**) |
| `gplanchat/durable-plugin` | [`src/DurablePlugin/`](src/DurablePlugin/) | Sylius 2 admin plugin: workflow dashboard, backend-neutral (**DUR037**) |
| `gplanchat/durable-phpstan` | [`src/DurablePhpstan/`](src/DurablePhpstan/) | PHPStan extension: resolves stub calls against their typed contract |
| `gplanchat/durable-rector` | [`src/DurableRector/`](src/DurableRector/) | Rector rules migrating a project off the official Temporal PHP SDK |
| *in-tree only* | [`src/DurableDemoContracts/`](src/DurableDemoContracts/) | The Nexus contracts (operations one service serves and another calls, like an activity) that the four bench applications share; deliberately not published |

### Benches

The applications that run the packages in CI, called benches, live in the same tree:

| Bench | Path | Role |
|--------|------|------|
| Symfony | [`symfony/`](symfony/) | Symfony application that runs the bundle, with Temporal integration tests |
| Sylius | [`sylius/`](sylius/) | Sylius 2.2 Standard, where the dashboard renders for real |
| Laravel | [`laravel/`](laravel/) | Laravel 12 application serving the `delivery` operation of the Nexus demonstration |
| Magento | [`magento/`](magento/) | Mage-OS 2.2 application with the `DurableProbe` module and its probes |

Durable uses neither the official Temporal PHP SDK nor RoadRunner as its runtime (**DUR006**).

## Documentation

- **User guide**: [`documentation/user/`](documentation/user/) is the Markdown source for the site at [durable.rocks](https://durable.rocks). Build instructions are in [`documentation/HUGO.md`](documentation/HUGO.md).
- **Contributor index** (ADRs, working agreements): [`documentation/INDEX.md`](documentation/INDEX.md)
- **Document lifecycle**: [`documentation/LIFECYCLE.md`](documentation/LIFECYCLE.md)
- **Per-package READMEs**: [`src/Durable/README.md`](src/Durable/README.md), [`src/DurableBundle/README.md`](src/DurableBundle/README.md), [`src/Bridge/Temporal/README.md`](src/Bridge/Temporal/README.md)
- **Monorepo to satellite repositories (splitsh)**: [`bin/splitsh-publish.sh`](bin/splitsh-publish.sh), [DUR020](documentation/adr/DUR020-monorepo-splitsh-and-satellite-repositories.md), [`.github/workflows/splitsh.yml`](.github/workflows/splitsh.yml). Pushes to `main`/`master` and tags `v*` propagate to satellites when `SPLITSH_PUSH_TOKEN` is configured.

Architecture decisions for this component use the **`DUR`** prefix under `documentation/adr/` (see **DUR000**).

## Working in the monorepo

From the repository root:

```bash
composer install
composer test
```

For the Symfony bench (workers, Docker, PHPUnit):

```bash
cd symfony
composer install
composer test
```

See [`symfony/README.md`](symfony/README.md) for Messenger consumers, `DURABLE_DSN`, and optional Temporal integration tests.

### Booting the Sylius bench

Sylius Standard 2.2 requires **PHP 8.3** and an extension set no PHP on a typical dev box carries in
one place, so the bench runs on the image the skeleton ships with, never on the host PHP:

```bash
cd sylius
cp compose.override.dist.yml compose.override.yml   # mounts the app, and `../src` it depends on
docker compose up -d                                # php 8.3, MySQL 8.4, nginx, mailhog
docker compose run --rm php composer install
docker compose run --rm php bin/console debug:router
```

`composer.lock` is tracked here, unlike in the upstream skeleton: this bench is a test bed in CI,
and an unpinned resolve would let a Sylius release break the build with no commit in this repository behind it.

## License

This project is released under the [MIT License](https://opensource.org/licenses/MIT) (SPDX identifier: `MIT`). The full text is in [`LICENSE`](LICENSE); distribution policy is described in [WA004](documentation/wa/WA004-mit-license-distribution.md).
