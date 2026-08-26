# `gplanchat/durable-bridge-dbal` (`src/Bridge/Dbal`)

Durable execution on **one SQL database**, with no orchestration cluster and no `ext-grpc`.

PHP namespace: **`Gplanchat\Bridge\Dbal`**. Decision record: **[DUR030](../../../documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md)**.

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
dependency. To manage them yourself, call `DurableSchema::addToSchema()` from your own schema
provider and keep the table names in sync with the configuration above.

The journal table has no `sequence` column — `readStream()` promises insertion order and the
auto-increment primary key carries it.

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
