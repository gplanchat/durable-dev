---
title: Configuration reference
weight: 35
---

# Configuration reference

This page documents every key accepted by `DurableBundle` in `config/packages/durable.yaml`.

---

## Full example

```yaml
durable:
    event_store:
        type: in_memory          # 'in_memory' (default)
    temporal:
        dsn: null                # set to temporal://… to activate the Temporal backend
    workflow_metadata:
        type: in_memory          # 'in_memory' (default)
    activity_transport:
        type: messenger          # 'messenger' (default) or 'in_memory'
        transport_name: durable_activities
    max_activity_retries: 0      # maximum automatic retries before marking an activity as failed
    activity_contracts:
        cache: cache.app         # PSR-6 cache pool for contract metadata (null = no cache)
        contracts:
            - App\Workflow\Activity\OrderActivities
    child_workflow:
        async_messenger: true    # true = child workflows dispatched via Messenger
        parent_link_store:
            type: in_memory      # 'in_memory' (default)
```

---

## `event_store`

Controls where workflow event history is stored.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `in_memory` | `in_memory` | Storage backend. `in_memory` keeps events in the PHP process — correct for tests and Temporal native (Temporal is the real history source). |

### When using Temporal

The `in_memory` event store is still correct when `temporal.dsn` is set. `TemporalReadThroughEventStore` wraps it: events missing locally are fetched from Temporal gRPC (`GetWorkflowExecutionHistory`) on demand, so the Symfony profiler DataCollector works across processes.

---

## `temporal`

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `dsn` | `temporal://host:port?…` or `null` | `null` | When `null`: In-Memory Messenger backend. When set: activates the Temporal gRPC backend (`ext-grpc` required). |

### DSN format

```
temporal://HOST:PORT?namespace=NAMESPACE&journal_task_queue=QUEUE&activity_task_queue=QUEUE&tls=0|1
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `namespace` | yes | Temporal namespace (e.g. `default`). |
| `journal_task_queue` | yes | Task queue for workflow tasks (e.g. `durable-journal`). |
| `activity_task_queue` | yes | Task queue for activity tasks (e.g. `durable-activities`). |
| `tls` | no (default `0`) | Set `tls=1` to enable TLS for the gRPC connection. |

**Example:**
```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
```

Use an environment variable:
```yaml
durable:
    temporal:
        dsn: '%env(DURABLE_DSN)%'
```

---

## `workflow_metadata`

Stores workflow type and initial payload, looked up by `executionId` when resuming.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `in_memory` | `in_memory` | In-process store. Correct for single-process tests and Temporal (metadata is persisted in Temporal history via the memo field). |

---

## `activity_transport`

How the bundle dispatches activity messages from workflow tasks to activity handlers.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `messenger`, `in_memory` | `messenger` | `messenger` routes activity messages via Symfony Messenger to the configured transport. `in_memory` executes activities synchronously within the workflow task handler. |
| `transport_name` | string | `durable_activities` | Name of the Messenger transport used when `type: messenger`. Must match a transport defined in `messenger.yaml`. |

---

## `max_activity_retries`

```yaml
durable:
    max_activity_retries: 3
```

Maximum number of automatic retries applied globally to activities before they are marked as failed. `0` means no automatic retries (the workflow receives the failure immediately). Override per-activity via `ActivityOptions::withMaxAttempts()`.

---

## `activity_contracts`

Pre-resolved activity contract metadata (method names, attributes) can be cached at container warm-up to avoid reflection overhead at runtime.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `cache` | string (service ID) or `null` | `null` | PSR-6 cache pool to use. `cache.app` is the Symfony default pool. Set `null` to disable caching (useful in `test` environment). |
| `contracts` | list of FQCN strings | `[]` | Activity contract interfaces to warm up. |

```yaml
durable:
    activity_contracts:
        cache: cache.app
        contracts:
            - App\Workflow\Activity\OrderActivities
            - App\Workflow\Activity\NotificationActivities
```

---

## `child_workflow`

Controls how child workflow dispatching works.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `async_messenger` | bool | `false` | When `true`, child workflow runs are dispatched via Messenger (async). When `false`, they run synchronously within the parent workflow task. |
| `parent_link_store.type` | `in_memory` | `in_memory` | Tracks parent→child links for completion propagation. |

---

## Environment-specific configuration (`when@`)

Use Symfony's `when@` syntax to change backends per environment:

```yaml
# Always use In-Memory (default for all envs not overridden below)
durable:
    event_store:
        type: in_memory
    temporal:
        dsn: null

# Temporal for dev and prod
when@dev:
    durable:
        temporal:
            dsn: '%env(DURABLE_DSN)%'

when@prod:
    durable:
        temporal:
            dsn: '%env(DURABLE_DSN)%'

# In-Memory forced for tests (overrides dev/prod even if DURABLE_DSN is set)
when@test:
    durable:
        temporal:
            dsn: null
        child_workflow:
            async_messenger: false
```

---

## See also

- [Backends](../backends/) — In-Memory vs Temporal: Docker setup, workers, DSN parameters.
- [Getting started](../getting-started/) — Messenger routing configuration.
- [Testing workflows](../testing/) — `DurableBundleTestTrait` and in-memory test configuration.
