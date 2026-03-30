# Durable

PHP library and Symfony bundle for durable execution: deterministic workflows, asynchronous activities, an event log, and transports (in-memory, DBAL, Messenger). API inspired by the [Temporal PHP SDK](https://docs.temporal.io/develop/php).

## Requirements

- PHP 8.2+
- Symfony 6.4+ or 7.4+ (for the bundle)

## Docker (PHP + Temporal)

[Docker](https://docs.docker.com/get-docker/) et **Compose v2** permettent d’aligner **PHP 8.2** (extensions **grpc**, **pcov**, SQLite, zip…) et un **Temporal** local sans installation manuelle.

| Service | Accès |
|--------|--------|
| Temporal (gRPC) | `127.0.0.1:7233` depuis l’hôte ; `temporal:7233` depuis les conteneurs |
| Temporal UI | http://localhost:8088 |
| PostgreSQL | réservé au cluster Temporal (réseau Compose) |

**Démarrer Temporal + UI** :

```bash
docker compose up -d
```

**Conteneur PHP** (profil `php`, pour ne pas builder au simple `up`) :

```bash
docker compose --profile php build php    # 1re fois : compilation de l’extension grpc
docker compose --profile php run --rm php composer install
docker compose --profile php run --rm php composer test
docker compose --profile php run --rm php bash
```

Fichiers : **`compose.yaml`**, **`docker/php/Dockerfile`**, **`.dockerignore`**. DSN type journal : `temporal-journal://temporal:7233?namespace=default&task_queue=durable-journal&tls=0` **depuis un service dans le même Compose** ; depuis l’hôte, garde `127.0.0.1:7233`.

**Magento** (MySQL + OpenSearch) : toujours **`docker-compose.magento-dev.yaml`** (`docker compose -f docker-compose.magento-dev.yaml up -d`).

Surcharge locale (ports, variables) : copier **`compose.override.example.yaml`** → **`compose.override.yaml`** (fichier ignoré par Git, fusionné automatiquement avec `compose.yaml`).

## Installing in a Symfony project

```bash
composer require gplanchat/durable-bundle
```

The bundle pulls in the **`gplanchat/durable`** component. **`symfony/messenger`** is required for Messenger-based activity transport and distributed workflow resume; the **library alone** does not declare that dependency (use **DBAL** or **in-memory** transports without the bundle if you avoid Messenger). Enable `Gplanchat\Durable\Bundle\DurableBundle` and copy configuration similar to `config/packages/durable.yaml` (see below and the sample app under `symfony/`).

> **This repository (monorepo)** : une seule arborescence de code sous **`src/`** et **`tests/`**. L’app exemple Symfony résout **`gplanchat/durable`** et **`gplanchat/durable-bundle`** via des dépôts *path* vers **`../src/Durable`** et **`../src/DurableBundle`**. Le bridge Temporal vit sous **`src/Bridge/Temporal`** (`Gplanchat\Bridge\Temporal`). La publication vers Packagist se fait avec **splitsh-lite** : voir **`bin/splitsh-publish.sh`**, le workflow **`.github/workflows/splitsh.yml`** (exécution sur chaque push vers `main` / `master`, plus déclenchement manuel), et les remotes `git@github.com:gplanchat/durable.git`, `durable-bundle.git`, **`durable-bridge-temporal.git`**, **`durable-magento.git`**, `durable-phpstan.git`, `durable-psalm-plugin.git`.

## Quick start (about 3 minutes)

To run a workflow **without setting up your own project** yet:

1. `cd symfony`
2. `composer install`
3. `php bin/console durable:schema:init`
4. `php bin/console durable:sample GreetingWorkflow --name=Alice`

You should see a greeting such as **Hello, Alice!**. For production-style workers (Messenger queues), see **`symfony/README.md`**.

> **Symfony project elsewhere** : install the bundle as above, reuse the sample `durable.yaml`, create tables (`durable:schema:init`), register your workflow and activity classes as in the *Symfony bundle* section, then start a workflow from a command or your code.

## Magento 2.4

The Composer package **`gplanchat/durable-magento`** lives under **`src/DurableModule/`** (module `Gplanchat_DurableModule`). It targets DBAL and (placeholder) Temporal backends without Messenger or RoadRunner. See **`magento/README.md`** and [ADR015](documentation/adr/ADR015-magento-durable-module.md).

## Concepts

- **Workflow** : orchestration replayed from a log. Steps that wait (activity, delay, child workflow, etc.) go through the **`WorkflowEnvironment`** object injected into your class.
- **Activity** : a unit of logic executed alongside the workflow (often via a queue). Assume it may be retried: keep it **idempotent** when you can.
- **Event log** : history used to resume or replay a workflow.

Optional **[Temporal](https://temporal.io) journal** (gRPC only, no Temporal PHP SDK): code under **`src/Bridge/Temporal`** (`Gplanchat\Bridge\Temporal`), published as **`gplanchat/durable-bridge-temporal`** — see [ADR014](documentation/adr/ADR014-temporal-journal-eventstore-bridge.md) and `src/Bridge/Temporal/README.md`.

## Class-based workflow

Workflow code depends only on the activity **contract** (a PHP **interface**). The concrete implementation is chosen at run time by the infrastructure (worker, Messenger, etc.):

```php
use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('GreetingWorkflow')]
final class GreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(GreetingActivityInterface::class);
    }

    #[WorkflowMethod]
    public function run(string $name = 'World'): string
    {
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
```

(`GreetingActivityInterface` is your domain interface, defined in the next section.)

## Activities

**Step 1 — contract** : a PHP interface and a method tagged with the activity name used by the runtime.

**Step 2 — implementation** : a class implementing that interface, like any other service in your app.

```php
namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

interface GreetingActivityInterface
{
    #[ActivityMethod('composeGreeting')]
    public function composeGreeting(string $name = 'World'): string;
}

#[AsDurableActivity(contract: GreetingActivityInterface::class)]
final class GreetingActivityHandler implements GreetingActivityInterface
{
    public function composeGreeting(string $name = 'World'): string
    {
        return sprintf('Hello, %s!', $name);
    }
}
```

The workflow only knows **`GreetingActivityInterface`**. On Symfony, **`#[AsDurableActivity]`** tells the bundle which interface is implemented: each method marked **`#[ActivityMethod]`** is **wired automatically** to the engine, with no manual registration in bootstrap code. Actual execution goes through your **workers** (e.g. Messenger consumers). Also list your activity interfaces under **`durable.activity_contracts.contracts`** in YAML for caching. Full example: **`symfony/src/Durable/`**.

## Retry options and error handling

Activity stubs are usually configured in the workflow constructor:

```php
use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;

public function __construct(
    private readonly WorkflowEnvironment $environment,
) {
    $this->greeting = $environment->activityStub(
        GreetingActivityInterface::class,
        ActivityOptions::default()
            ->withMaxAttempts(3)
            ->withInitialInterval(2.0)
            ->withNonRetryableExceptions([ValidationException::class]),
    );
}
```

## Symfony bundle

Configuration excerpt (full sample: **`symfony/config/packages/durable.yaml`** in this repo):

```yaml
# config/packages/durable.yaml
durable:
    distributed: true
    dbal_connection: default  # or durable — see documentation/adr/ADR016-dedicated-dbal-connection-and-unbuffered-reads.md
    event_store:
        type: dbal              # or in_memory
        table_name: durable_events
    workflow_metadata:          # required if distributed + Messenger resume
        type: dbal              # or in_memory
        table_name: durable_workflow_metadata
    activity_transport:
        type: messenger         # or in_memory, dbal
        transport_name: durable_activities
    child_workflow:
        async_messenger: true   # child = separate WorkflowRunMessage (with distributed)
        parent_link_store:
            type: dbal           # or in_memory — persist parent↔child link for multi-worker
            table_name: durable_child_workflow_parent_link
    activity_contracts:
        cache: cache.app
        contracts:
            - App\Durable\Activity\GreetingActivityInterface
```

Registering workflows (`#[Workflow]` classes) and activity handlers:

```yaml
# config/services.yaml
services:
    App\Durable\Workflow\:
        resource: '../src/Durable/Workflow/'
        tags: [durable.workflow]
    App\Durable\Activity\:
        resource: '../src/Durable/Activity/*Handler.php'
```

Classes annotated with **`#[AsDurableActivity(contract: …)]`** are discovered by the bundle and registered as **activity implementations** (no imperative PHP needed to plug them into the engine).

**DBAL schema** (log, metadata, parent–child link): implementations expose `createSchema()`. In the sample app:

```bash
cd symfony && php bin/console durable:schema:init
```

**Dedicated DBAL connection and unbuffered reads (MySQL)** — The bundle supports **`durable.dbal_connection`** (Doctrine connection name) so Durable stores use a **separate `Connection`** from the rest of the app. For **large** histories on **MySQL**, you can disable **buffered** queries on that connection only (`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY`) so the driver does not load the full result set into client memory. Constraints (no concurrent statements on the same connection while streaming) and YAML examples: **[ADR016](documentation/adr/ADR016-dedicated-dbal-connection-and-unbuffered-reads.md)**.

**Workers** (transport names should match your `messenger.yaml`):

```bash
php bin/console messenger:consume durable_workflows durable_activities -vv
```

Distributed mode with **`activity_transport.type: messenger`** runs activities through **`ActivityRunHandler`** on the activity transport (no separate console command).

## Symfony sample

The **`symfony/`** directory is the reference application (workflows, activities, Messenger, SQLite). After the *quick start*, you can run other samples:

```bash
cd symfony && php bin/console durable:sample ParallelGreetingWorkflow --first=Ada --second=Grace
```

PHPUnit for the sample app:

```bash
cd symfony && composer test
```

## Tests (component)

```bash
composer test
composer cs:check   # code style check
# Par couche (dossiers en minuscules) : vendor/bin/phpunit tests/unit | tests/functional | tests/integration | tests/e2e
```

### Mutation testing (Infection)

[Infection](https://infection.github.io/) complète la **couverture de lignes** : il **mute** le code sous `src/Durable`, `src/DurableBundle` et `src/Bridge/Temporal` ; si les tests ne détectent pas la mutation, la zone est considérée comme **mal couverte** (tests faibles ou assertions insuffisantes).

**Prérequis** : génération de couverture — **`ext-pcov`** (recommandé), **Xdebug** (`xdebug.mode=coverage`), ou lancer via **`phpdbg -qrr vendor/bin/infection ...`**.

```bash
composer infection:unit          # PHPUnit --testsuite=unit
composer infection:functional    # PHPUnit --testsuite=functional
composer infection:unit-fast     # idem unit + --only-covered (moins de mutants, plus rapide)
composer infection               # toutes les suites PHPUnit (unit + functional + integration + e2e)
```

Configuration : **`infection.json.dist`** (rapports HTML/texte sous **`var/infection/`**, déjà ignoré via `var/`). Le module Magento (`src/DurableModule`) et les paquets d’analyse statique ne sont **pas** dans le périmètre de mutation.

## Repository layout

```
src/
├── Durable/              # Paquet gplanchat/durable (cœur + composer.json pour split)
├── DurableBundle/        # Paquet gplanchat/durable-bundle
├── Bridge/
│   └── Temporal/         # Paquet gplanchat/durable-bridge-temporal (journal gRPC, sans SDK Temporal)
├── DurableModule/        # Module Magento 2 (split → gplanchat/durable-magento ou équivalent)
├── DurablePhpStan/       # Extension PHPStan (paquet gplanchat/durable-phpstan)
└── DurablePsalmPlugin/   # Plugin Psalm (paquet gplanchat/durable-psalm-plugin)
tests/
├── unit/                 # namespace unit\Gplanchat\…
├── functional/           # namespace functional\Gplanchat\…
├── integration/          # namespace integration\Gplanchat\…
└── e2e/                  # namespace e2e\Gplanchat\…
symfony/                  # Application exemple
documentation/          # ADR, OST, PRD
bin/splitsh-publish.sh    # Aide pour splitsh-lite → dépôts GitHub
```

## Documentation

- **Split / Packagist** : `bin/splitsh-publish.sh` ; CI **Splitsh** (`.github/workflows/splitsh.yml`) — préfixes `src/Durable`, `src/DurableBundle`, `src/Bridge/Temporal`, `src/DurableModule`, `src/DurablePhpStan`, `src/DurablePsalmPlugin` ; cache du binaire **splitsh-lite** (restore / build / save) ; secret optionnel **`SPLITSH_PUSH_TOKEN`** (PAT avec droit `contents` sur chaque dépôt satellite) pour **pousser** automatiquement les SHA — voir **[ADR017](documentation/adr/ADR017-splitsh-ci-and-satellite-pushes.md)**
- [Documentation index](documentation/INDEX.md) — ADR, conventions, OST
- [ADR016](documentation/adr/ADR016-dedicated-dbal-connection-and-unbuffered-reads.md) — Dedicated DBAL connection and MySQL unbuffered reads
- [CHANGELOG.md](CHANGELOG.md) — API breaks and changes

## License

Proprietary.
