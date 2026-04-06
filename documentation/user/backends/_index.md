---
title: Backends
weight: 15
---

# Backends

Durable supports two execution backends. You choose by setting (or not setting) `DURABLE_DSN` in your environment.

| Backend | Use case |
|---------|----------|
| **In-Memory** | Unit tests, functional tests, local exploration — no Temporal server needed. |
| **Temporal** | Production, staging, realistic integration tests — `ext-grpc` + a Temporal cluster required. |

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

## Choosing a backend per environment

```
┌──────────────────────┬───────────────────────────────────────────────────┐
│ Environment          │ Backend                                           │
├──────────────────────┼───────────────────────────────────────────────────┤
│ Unit tests           │ In-Memory (DurableTestCase)                       │
│ Integration tests    │ In-Memory (DurableBundleTestTrait + KernelTestCase│
│ CI with Temporal     │ Temporal (temporal-integration group)             │
│ Local dev            │ Either (In-Memory for speed, Temporal for realism)│
│ Production           │ Temporal                                          │
└──────────────────────┴───────────────────────────────────────────────────┘
```

---

## See also

- [Configuration reference](../configuration/) — full `durable.yaml` key list.
- [Getting started](../getting-started/) — Messenger routing and worker commands.
- [Testing workflows](../testing/) — using the In-Memory backend in tests.
