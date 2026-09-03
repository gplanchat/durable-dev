# `gplanchat/durable-bridge-dbal` (`src/Bridge/Dbal`)

Durable execution on **one SQL database**, with no orchestration cluster and no `ext-grpc`.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **The tests are in the monorepo, not here.** This split carries source only. What covers it is
> `tests/unit/Bridge/Dbal/` in the monorepo, run by its `unit` suite.
>
> **Documentation**: [durable.rocks](https://durable.rocks).

PHP namespace: **`Gplanchat\Bridge\Dbal`**. Decision record: **[DUR030](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md)**.

The core replay interpreter, the workflow ports and the command buffer are unchanged — this bridge
only makes the three process-local stores persistent. Workflow and activity code is identical to
what runs on Temporal or In-Memory.

## What you get, and what you give up

| Kept | Given up (vs. the Temporal backend) |
|---|---|
| Workflow classes, activities, `WorkflowEnvironment` | Distributed task queues — resumes ride Symfony Messenger |
| Signals, queries, updates | Server-side scheduling; timers ride Messenger `DelayStamp` |
| Cancellation and compensation semantics | Server-side task serialisation — replaced by an application lock |
| Replay determinism and the event journal | History retention, visibility API, Temporal UI |

## Requirements

- `doctrine/dbal` **^3.7 \|\| ^4.0**
- `symfony/lock` — with a **cross-process** lock store (DBAL, Redis, …) as soon as you run more than
  one worker. See *Concurrency* below.

## Components

| Class | Role |
|---|---|
| `Store\DbalEventStore` | `Gplanchat\Durable\Store\EventStoreInterface` over `durable_events` |
| `Store\DbalWorkflowMetadataStore` | `Gplanchat\Durable\Store\WorkflowMetadataStore` over `durable_workflow_metadata` |
| `Store\DbalChildWorkflowParentLinkStore` | `Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface` over `durable_child_workflow_parent_link` |
| `Store\DbalWorkflowRunCatalog` | `Gplanchat\Durable\Port\WorkflowRunCatalogInterface` over `durable_workflow_runs` — what the dashboard reads |
| `Store\DbalWorkflowRunProjection` | Writes that table: the name on start, the outcome on end |
| `Store\ProjectingWorkflowMetadataStore`, `Store\ProjectingEventStore` | Decorators that feed the projection without touching what they wrap |
| `Schema\DurableSchema` | Lazy table creation on first write (Messenger Doctrine transport pattern) |
| `Messenger\SingleResumeLockMiddleware` | One resume at a time per execution |

Records go through `Gplanchat\Durable\Mapping\EventDataMapper`, the same boundary the Temporal
journal uses — rows and gRPC journal items share one shape.

## Configuration

```yaml
# config/packages/durable.yaml
durable:
    dbal:
        connection: doctrine.dbal.default_connection
        lock_factory: lock.factory
    event_store:
        type: dbal
        table_name: durable_events
    workflow_metadata:
        type: dbal
        table_name: durable_workflow_metadata
    child_workflow:
        parent_link_store:
            type: dbal
            table_name: durable_child_workflow_parent_link
    activity_transport:
        type: messenger
        transport_name: durable_activities
```

Use a **durable** Messenger transport (Doctrine, Redis, AMQP) for `durable_workflows` and
`durable_activities` — an `in-memory://` transport throws away everything the SQL journal just
persisted.

`event_store.type: dbal` together with a non-empty `temporal.dsn` throws at compile time: the
journal cannot have two sources of truth.

## Schema

Tables are created on first write; there is no migration to run and no `doctrine/migrations`
dependency.

**With Doctrine ORM installed, they are also declared to its tooling.** The bundle registers a
`postGenerateSchema` listener, so `doctrine:schema:update` and `doctrine:migrations:diff` know these
tables belong to the application. Without it they would look like orphans and a generated migration
would **drop them** — with the journal, every in-flight execution.

The listener declares the tables only when the journal writes on the very `Connection` the ORM
inspects. Two distinct `Connection` objects can point at the same database, and proving it takes a
probe this bundle does not run; not declaring leaves that schema to you, whereas declaring wrongly
would create tables in the wrong database. Pass your own probe to
`DurableSchema::configureSchema($schema, $connection, $isSameDatabase)` if you need the other case.

Once migrations own the schema, turn the lazy creation off — otherwise both mechanisms write
behind each other:

```yaml
durable:
    dbal:
        auto_setup: false
```

To manage the tables entirely yourself, call `DurableSchema::addToSchema()` from your own schema
provider and keep the table names in sync with the configuration above.

The journal table has no `sequence` column — `readStream()` promises insertion order and the
auto-increment primary key carries it.

### `durable_workflow_runs` — the row a run leaves behind

A fourth table exists so that a finished run stays describable. It holds one row per execution:
`execution_id`, `workflow_type`, `status`, `started_at`, `ended_at`.

It is needed because neither of the other tables can answer "what ran, and what became of it":

- `ExecutionStarted` carries an execution id and a payload — **not** the workflow type;
- the metadata row that does carry the type is **deleted** when a run fails, is cancelled, or
  continues as new. Only a successful completion keeps one.

So without this table a failed run has no name anywhere, which is unfortunate for a dashboard whose
main job is to list failures. `durable_workflow_metadata` stays what it was: a registry of live
executions, whose very presence means "still running" to `hasActiveWorkflowMetadata()`.

The row is written by two hands, because neither knows the whole story. The name comes from
`save()` on the metadata store — the only unambiguous call, and the only one carrying the workflow
type. The outcome comes from the journal, where `ExecutionCompleted`, `WorkflowExecutionFailed`,
`WorkflowExecutionCancelled` and `WorkflowContinuedAsNew` each arrive typed; on the metadata side
those three endings are the same `delete()` call and cannot be told apart.

A run that continues as new leaves **two independent rows** — one that ends, one that starts — and
nothing links them. The component already mints a fresh execution id for the successor, so a link
here would be the only place claiming they are one thing.

Retention is the application's job, as for the journal. The table grows by one row per execution,
never by one per event.

## Concurrency — read this before running two workers

Temporal serialises workflow tasks for one execution server-side. There is no server here, so two
consumers can dequeue two resumes of the same execution and replay the same fiber in parallel,
each appending its own commands: **duplicated activities, forked journal**.

`SingleResumeLockMiddleware` prevents that by taking a `durable-resume-{executionId}` lock around
`ResumeWorkflowMessage` and `FireWorkflowTimersMessage`. It is registered automatically when the
DBAL event store is active. Acquisition is blocking — the second worker waits rather than requeuing.

**This backend is only as safe as its lock store.** `lock.factory` backed by an in-memory or
per-process store with several workers gives back exactly the failure the middleware exists to
prevent. Configure a shared store:

```yaml
framework:
    lock:
        default: '%env(LOCK_DSN)%'   # e.g. doctrine://default or redis://…
```

## Tests

`tests/unit/Bridge/Dbal/DbalStoresTest.php` runs the three stores against SQLite in memory — real
SQL, no server.

## License

MIT — see [`LICENSE`](LICENSE).
