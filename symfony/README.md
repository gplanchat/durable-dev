# Durable + Symfony sample application

Demonstrates `gplanchat/durable` with **Messenger**, **Temporal** (gRPC workers), and class-based workflows (`App\Durable\Workflow\`). Durable persistence **without DBAL** in this sample: Temporal journal or in-memory depending on environment.

## Requirements

- PHP 8.2+
- Composer — dependencies live under **`symfony/vendor/`**. Run **`composer install`** from this `symfony/` directory.
- Packages **`gplanchat/durable`** and **`gplanchat/durable-bundle`** resolve via **path** from **`../src/Durable`** and **`../src/DurableBundle`** at the monorepo root. For an app outside the monorepo: `composer require gplanchat/durable-bundle` from Packagist.

After changing the vendor layout, clear cache: **`rm -rf var/cache/*`** then **`php bin/console cache:clear`**.

## Installation

```bash
cd symfony
composer install
```

## Architecture

### WorkflowClient

`WorkflowClient` is the entry point for workflows from application code (controllers, commands, services):

```php
// Start a workflow (fire-and-forget)
$executionId = $client->start('MyWorkflow', ['key' => 'value']);

// Start and wait until completion (synchronous)
$result = $client->startSync('MyWorkflow', ['key' => 'value']);

// Send a signal
$client->signal($executionId, 'approve', ['approved' => true]);

// Query workflow state
$status = $client->query($executionId, 'getStatus');

// Send an update (signal with a return value)
$result = $client->update($executionId, 'increment', ['amount' => 1]);
```

### ResumeWorkflowMessage

`WorkflowClient` (and `WorkflowResumeDispatcher`) dispatches a `ResumeWorkflowMessage` containing only the `executionId`. Metadata (workflow type, initial payload) is stored in `WorkflowMetadataStore` before dispatch. `ResumeWorkflowHandler` loads that metadata to resume or start execution.

### WorkflowTaskRunner (native Temporal backend)

For the native Temporal backend, `WorkflowTaskRunner`:

1. Receives a `PollWorkflowTaskQueueResponse` with Temporal history
2. Builds a `TemporalExecutionHistory` with O(1) event lookups
3. Runs the workflow handler in a standard PHP **`Fiber`**
4. Replays history: resolved awaitables resume immediately
5. Stops on the first unresolved awaitable (new command) and returns Temporal commands

No `pcntl_fork()`, no Swoole, no RoadRunner — **plain PHP CLI**.

### Temporal History Cursor

`TemporalHistoryCursor` lazily pages Temporal history via `next_page_token` without loading the full history into memory at once.

## Dev: Temporal + Messenger workers

See **`.env.dev`**: **`DURABLE_DSN`** (`temporal://…`), **`durable.temporal.dsn`** enables the Temporal bridge.

Typical workers (via `symfony serve` or manual runs):

```bash
# Journal worker (polls Temporal for workflow tasks)
php bin/console messenger:consume durable_temporal_journal

# Activity worker (polls Temporal for activity tasks)
php bin/console messenger:consume durable_temporal_activity

# In-memory workflow worker (Messenger backend)
php bin/console messenger:consume durable_workflows
```

See **`.symfony.local.yaml`** for full worker configuration when using `symfony serve`.

## PHPUnit (this app)

```bash
cd symfony
composer test
# or: php bin/phpunit
```

Tests cover **`durable:sample`** example workflows and, when configured, real Temporal integration.

### Temporal (real integration, optional)

Requires a Temporal frontend reachable from the machine running PHPUnit (often `docker compose up -d`). The host port may differ from `7233` if `TEMPORAL_FRONTEND_PORT` is set — check with `docker compose port temporal 7233`.

- Prerequisite: **ext-grpc**
- Variable: **`DURABLE_DSN`**, e.g. `temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0` (adjust the port)
- Command: `composer test:temporal-integration` or `php bin/phpunit --group temporal-integration`

Without a DSN or a reachable server, tests in group **`temporal-integration`** are **skipped** (the default `composer test` suite stays green).

## Bundle configuration (`durable.yaml`)

```yaml
durable:
    temporal:
        dsn: '%env(DURABLE_DSN)%'
        # Option interpreter_mirror_activities was removed in the refactor.
        # WorkflowTaskRunner handles native replay via Fiber (DUR027).
```

The `interpreter_mirror_activities` key was removed. The Temporal bridge now uses `WorkflowTaskRunner` + `TemporalHistoryCursor` for native replay (see **DUR027**).

## License

**MIT** — see [`LICENSE`](LICENSE) in this directory and [WA004](../documentation/wa/WA004-mit-license-distribution.md).
