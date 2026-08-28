---
title: Backends
weight: 15
---

# Backends

Durable supports three execution backends.

| Backend | Use case |
|---------|----------|
| **In-Memory** | Unit tests, functional tests, local exploration — no server needed. |
| **DBAL** | Production without an orchestration cluster — one SQL database, no `ext-grpc`. |
| **Temporal** | Production and staging at scale, realistic integration tests — `ext-grpc` + a Temporal cluster required. |

> [!NOTE]
> **On Magento, the SQL row does not exist.** `gplanchat/durable-magento` declares a Composer
> `conflict` on both SQL bridges: `Magento\Framework\App\ResourceConnection` is neither Doctrine
> DBAL nor Illuminate's connection, so neither bridge has anything to bind to. The state lives in a
> Temporal cluster, or it lives in one process — and the choice is made by the presence of
> `durable/temporal/dsn` in `app/etc/env.php`, not by a setting.

All three run the **same fiber driver** and the same workflow and activity code. You choose with
`durable.event_store.type` (and `DURABLE_DSN` for Temporal).

---

## In-Memory backend

The In-Memory backend runs entirely inside a single PHP process. There is no external server, no gRPC, and no persistence between requests.

### How it works

- Workflow and activity messages are dispatched through **Symfony Messenger** in-memory transports.
- The event history lives in an `InMemoryEventStore`.
- The Messenger drain processes messages synchronously when you call `drainMessengerUntilSettled()` or the equivalent.

### Configuration

```yaml
# config/packages/durable.yaml (or when@test:)
durable:
    event_store:
        type: in_memory
    temporal:
        dsn: null
    workflow_metadata:
        type: in_memory
    activity_transport:
        type: messenger
        transport_name: durable_activities

# config/packages/messenger.yaml (or when@test:)
framework:
    messenger:
        transports:
            durable_workflows:  'in-memory://'
            durable_activities: 'in-memory://'
        routing:
            Gplanchat\Durable\Transport\ResumeWorkflowMessage: durable_workflows
            Gplanchat\Durable\Transport\ActivityMessage:       durable_activities
```

### When to use it

- All **unit and functional tests** (see [Testing workflows](../testing/)).
- **Local development** when you do not need Temporal's durable history or UI.
- **CI jobs** that run without Docker.

---

## Temporal backend

The Temporal backend delegates workflow orchestration to a real **Temporal** cluster. The PHP process communicates over **gRPC** via `ext-grpc`.

### How it works

1. When `DURABLE_DSN` is set, `DurableExtension` registers the Temporal-specific services (`WorkflowClient`, `TemporalHistoryCursor`, workers).
2. Starting a workflow calls `StartWorkflowExecution` gRPC on Temporal.
3. **Workflow tasks** are polled by the `durable_temporal_journal` Messenger consumer.
4. **Activity tasks** are polled by the `durable_temporal_activity` Messenger consumer.
5. Each workflow task replays history via the fiber-based `WorkflowTaskRunner` and sends back commands to Temporal.

### Prerequisites

- **`ext-grpc`** PHP extension compiled against the `grpc/grpc` package version required by the bridge.
- A running Temporal cluster.

### Install `ext-grpc`

```bash
pecl install grpc
# Add to php.ini: extension=grpc
```

Verify:

```bash
php -m | grep grpc
```

**In a container image, don't compile it again.** `pecl install grpc` takes about seven minutes, and
your image build pays it on every branch. Prebuilt extensions are published for PHP 8.2 to 8.5, in
thread-safe and non-thread-safe forms — see [gRPC in your container image](../container-images/)
for the `COPY --from` recipes, including php-fpm, mod_php and FrankenPHP.

### Docker Compose setup (local / CI)

The repository includes a ready-to-use `compose.yaml` under `symfony/` that starts:
- **PostgreSQL 16** (shared between the application and Temporal)
- **`temporalio/auto-setup:1.25.2`** (auto-configures schema on startup)
- **Temporal UI** (on port 8088)

```bash
cd symfony
docker compose up -d
```

Wait for the stack to be healthy, then start the Symfony workers:

```bash
php bin/console messenger:consume durable_temporal_journal --time-limit=3600
php bin/console messenger:consume durable_temporal_activity --time-limit=3600
```

The `symfony serve` binary reads `.symfony.local.yaml` and starts workers automatically if configured there.

### Configuration

```yaml
# .env.local (dev/prod)
DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
MESSENGER_DURABLE_WORKFLOW_DSN=in-memory://
MESSENGER_DURABLE_ACTIVITY_DSN=in-memory://
```

```yaml
# config/packages/durable.yaml
durable:
    event_store:
        type: in_memory   # Temporal is the real history source; in-memory acts as a local write-through cache
    temporal:
        dsn: '%env(DURABLE_DSN)%'

# config/packages/messenger.yaml
when@dev:
    framework:
        messenger:
            transports:
                durable_temporal_journal:
                    dsn: '%env(DURABLE_DSN)%'
                durable_temporal_activity:
                    dsn: '%env(DURABLE_DSN)%'
                    options:
                        purpose: activity_worker
            routing:
                Gplanchat\Durable\Transport\FireWorkflowTimersMessage: durable_workflows
```

### Temporal UI

With the default Docker setup, the **Temporal Web UI** is available at [http://localhost:8088](http://localhost:8088). It shows running and completed workflows, their history, and failed activities.

### DSN parameters

| Parameter | Required | Example | Description |
|-----------|----------|---------|-------------|
| `namespace` | yes | `default` | Temporal namespace. Use distinct namespaces per application/environment. |
| `journal_task_queue` | yes | `durable-journal` | Task queue for the workflow task worker. |
| `activity_task_queue` | yes | `durable-activities` | Task queue for the activity worker. |
| `tls` | no (default `0`) | `tls=1` | Enable TLS for gRPC. Required for Temporal Cloud. |

### Temporal Cloud

For **Temporal Cloud**, set TLS and the Cloud endpoint:

```
DURABLE_DSN=temporal://ACCOUNT.REGION.tmprl.cloud:7233?namespace=NAMESPACE.ACCOUNT&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=1
```

TLS certificates can be mounted and configured via gRPC channel credentials (see the bridge source for extension points).

---

## DBAL backend

The DBAL backend persists the journal, the resume metadata and the parent/child links in a **single
SQL database** through Doctrine DBAL. There is no orchestration server, no sidecar and no
`ext-grpc`. See **DUR030**.

### How it works

- The three process-local stores become SQL tables; everything else — replay, command buffer,
  lifecycle — is the code the In-Memory backend already runs.
- Resumes and activities ride **Symfony Messenger**, so use a durable transport (Doctrine, Redis,
  AMQP). An `in-memory://` transport throws away what the SQL journal just persisted.
- Timers ride Messenger `DelayStamp` through `FireWorkflowTimersHandler`.
- Tables are created on **first write** — no migration to run, no `doctrine/migrations` dependency.

### Configuration

```yaml
# config/packages/durable.yaml
durable:
    dbal:
        connection: doctrine.dbal.default_connection
        lock_factory: lock.factory
    event_store:
        type: dbal
    workflow_metadata:
        type: dbal
    child_workflow:
        parent_link_store:
            type: dbal
    activity_transport:
        type: messenger
        transport_name: durable_activities

framework:
    lock:
        default: '%env(LOCK_DSN)%'   # doctrine://default, redis://… — must be shared across workers
```

Setting `event_store.type: dbal` together with a non-empty `temporal.dsn` throws at compile time:
the journal cannot have two sources of truth.

### One resume at a time — the thing to get right

Temporal serialises workflow tasks for one execution server-side. There is no server here, so two
consumers can dequeue two resumes of the same execution and replay the same fiber in parallel,
each appending its own commands: **duplicated activities, forked journal**.

Durable prevents this with a per-execution lock (`SingleResumeLockMiddleware`), registered
automatically when the DBAL event store is active. **It is only as safe as your lock store**: an
in-memory or per-process `lock.factory` with several workers gives back exactly the failure the
lock exists to prevent. Configure a shared one.

### When to use it

- **Production without operating a cluster** — a Symfony app that already has a database and a
  Messenger transport.
- Long-running workflows that must survive deploys and restarts, at a scale one database can hold.

Not for: search-attribute queries, cron schedules, or the throughput and visibility a Temporal
cluster gives you. See the capability matrix below.

### The same trade, on Laravel's connection

The same four stores exist on `Illuminate\Database\Connection`, as
[`gplanchat/durable-bridge-illuminate`](../packages/#gplanchatdurable-bridge-illuminate--the-laravel-backend)
— same journal, same trade against Temporal.

It is **not a fourth value of `event_store.type`**, and it never will be: a Laravel application does
not read this page's YAML. The bridge is the storage half, and **what binds it is
`gplanchat/durable-laravel`**, through its own published `config/durable.php`.

That package carries the queue side too — activities and resumes as jobs, a timer as a deferred
resume on the queue's own delay, and the per-execution exclusion the section above describes. Its
own [Packages entry](../packages/#gplanchatdurable-laravel--the-laravel-integration) has the
configuration, the three settings it refuses rather than tolerates, and the two behaviours that read
like bugs and are not.

**The trade against Temporal is the DBAL one, word for word.** What changes is the connection, and
why: a store on `DB::connection()` is inside `DB::transaction()` by construction, which is what
DUR030 needs. See [DUR047](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR047-laravel-the-host-that-measured-before-it-wired.md).

---

## Choosing a backend per environment

```
┌──────────────────────┬───────────────────────────────────────────────────┐
│ Environment          │ Backend                                           │
├──────────────────────┼───────────────────────────────────────────────────┤
│ Unit tests           │ In-Memory (DurableTestCase)                       │
│ Integration tests    │ In-Memory (DurableBundleTestTrait + KernelTestCase│
│ CI with Temporal     │ Temporal (temporal-integration group)             │
│ Local dev            │ Any (In-Memory for speed, DBAL/Temporal for realism)│
│ Production, no cluster│ DBAL                                             │
│ Production, at scale │ Temporal                                          │
└──────────────────────┴───────────────────────────────────────────────────┘
```

---

## Capability matrix

All three backends run the **same fiber driver** and the same activity execution path. What differs
is what the surrounding platform can offer.

| Capability | In-Memory | DBAL | Temporal |
|---|---|---|---|
| Activities, retries, timeouts | ✅ | ✅ | ✅ |
| Timers, side effects | ✅ | ✅ (Messenger delays) | ✅ |
| Signals, updates, queries | ✅ | ✅ | ✅ |
| Child workflows | ✅ | ✅ | ✅ |
| `ParentClosePolicy` cascade | ✅ | ✅ | ✅ (server-driven) |
| Continue-as-new | ✅ | ✅ | ✅ |
| Cancellation with compensation | ✅ | ✅ | ✅ |
| Survives process restart | ❌ | ✅ | ✅ |
| Task serialisation per execution | n/a (single process) | application lock | ✅ server-side |
| Search attributes | journaled only | journaled only | ✅ indexed and queryable |
| Cron schedules | ❌ no scheduler | ❌ no scheduler | ✅ |
| History retention / visibility API | ❌ | your SQL table | ✅ |
| Nexus operations (call **and** serve) | ❌ | ❌ | ✅ |

Neither the in-memory nor the DBAL backend has a scheduler or a cross-namespace boundary, so cron
and Nexus have no equivalent there. Where a capability is missing it **fails explicitly** rather
than being silently ignored — for a Nexus *call*, at the call; for a Nexus *handler*, when the
container is built, since a handler with no route is not a call that fails but a service that never
receives anything.

---

## Retry semantics are identical

An activity with no attempt bound retries **indefinitely** on both backends — the Temporal default.
The bundle's `max_activity_retries` still acts as a ceiling when an activity does not set its own;
at `0` it caps nothing.

See [Failures and retries](../failures/) and [Options](../options/#retrylimit).

---

## Writing your own backend

Two ports define a backend: `WorkflowCommandBufferInterface` for what a workflow asks for, and
`WorkflowHistorySourceInterface` for what already happened.

Both carry **value objects**, not primitives. An implementation receives the options as the caller
built them — retry limits, timeouts, task queues, cron schedules — and owns the translation to its
own representation, including serialisation and any reading of a clock.

`startTimer()` receives a **delay**, not a deadline: turning it into an instant is your decision,
with your clock. That is what lets a test harness advance a virtual clock, and what lets the
Temporal driver pass the duration the server expects.

The contributor decision record is [DUR031](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR031-value-objects-across-ports-and-wire-ownership.md).

---

## See also

- [Configuration reference](../configuration/) — full `durable.yaml` key list.
- [Getting started](../getting-started/) — Messenger routing and worker commands.
- [Testing workflows](../testing/) — using the In-Memory backend in tests.
