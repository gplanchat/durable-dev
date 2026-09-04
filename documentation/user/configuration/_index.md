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
    dbal:                                    # only read when a type below is 'dbal'
        connection: doctrine.dbal.default_connection
        lock_factory: lock.factory           # must be shared across workers
    event_store:
        type: in_memory                      # 'in_memory' (default) or 'dbal'
        table_name: durable_events
    temporal:
        dsn: null                            # set to temporal://… to activate the Temporal backend
        journal: true                        # false: the cluster is reachable, event_store stays the journal
    workflow_metadata:
        type: in_memory                      # 'in_memory' (default) or 'dbal'
        table_name: durable_workflow_metadata
    activity_transport:
        type: messenger                      # 'in_memory' is the DEFAULT — set 'messenger' to route
        transport_name: durable_activities
        table_name: durable_activity_outbox
    max_activity_retries: 0                  # maximum automatic retries before marking an activity as failed
    messenger:
        buses: []                            # [] = every bus (default)
    activity_contracts:
        cache: cache.app                     # PSR-6 cache pool for contract metadata (default: null, no cache)
        contracts:
            - App\Workflow\Activity\OrderActivities
    child_workflow:
        async_messenger: true                # true = child workflows dispatched via Messenger
        parent_link_store:
            type: in_memory                  # 'in_memory' (default) or 'dbal'
            table_name: durable_child_workflow_parent_link
```

> [!IMPORTANT]
> **`activity_transport.type` defaults to `in_memory`, not `messenger`.** Omit the key and activities
> run **synchronously inside the workflow task**, whatever transport `messenger.yaml` defines. Every
> example on this site sets it explicitly for that reason. See
> [`activity_transport`](#activity_transport).

---

## `dbal`

Where the SQL backend gets its connection and its lock. Read only when one of the three `type` keys
below is set to `dbal`; ignored otherwise, so it costs nothing to leave at its defaults.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `connection` | service ID | `doctrine.dbal.default_connection` | The `Doctrine\DBAL\Connection` the stores write to. |
| `lock_factory` | service ID | `lock.factory` | The `LockFactory` that serialises resumes of one execution. **It is only as safe as your lock store** — an in-memory or per-process factory with several workers gives back the failure the lock exists to prevent. |

The trade this backend makes, and why the lock is load-bearing, are on the
[Backends](../backends/#dbal-backend) page.

---

## `event_store`

Controls where workflow event history is stored.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `in_memory`, `dbal` | `in_memory` | Storage backend. `in_memory` keeps events in the PHP process — correct for tests and Temporal native (Temporal is the real history source). `dbal` persists them in SQL, and is what makes a run survive a restart without a cluster. |
| `table_name` | string | `durable_events` | Table the `dbal` store writes to. Created on first write. |

### When using Temporal

The `in_memory` event store is still correct when `temporal.dsn` is set. `TemporalReadThroughEventStore` wraps it: events missing locally are fetched from Temporal gRPC (`GetWorkflowExecutionHistory`) on demand, so the Symfony profiler DataCollector works across processes.

---

## `temporal`

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `dsn` | `temporal://host:port?…` or `null` | `null` | When `null`: In-Memory Messenger backend. When set: activates the Temporal gRPC backend (`ext-grpc` required). |
| `journal` | `true` / `false` | `true` | `false` says the cluster is reachable **without** being the journal: `event_store` stays the source of truth, and the dashboard keeps reading it. That is how an application with a DBAL journal serves a Nexus operation — see [Nexus operations](../nexus/). Setting a DSN with `journal: true` alongside `event_store.type: dbal` is refused: the journal cannot have two sources of truth. |

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
| `type` | `in_memory`, `dbal` | `in_memory` | In-process store. Correct for single-process tests and Temporal (metadata is persisted in Temporal history via the memo field). `dbal` persists it in SQL. |
| `table_name` | string | `durable_workflow_metadata` | Table the `dbal` store writes to. Created on first write. |

---

## `activity_transport`

How the bundle dispatches activity messages from workflow tasks to activity handlers.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `in_memory`, `messenger` | **`in_memory`** | `in_memory` executes activities **synchronously within the workflow task handler** — that is what you get when the key is absent. `messenger` routes activity messages via Symfony Messenger to the configured transport. |
| `transport_name` | string | `durable_activities` | Name of the Messenger transport used when `type: messenger`. Must match a transport defined in `messenger.yaml`. |
| `table_name` | string | `durable_activity_outbox` | Outbox table name. |

**The default is the one you probably do not want in production.** Defining `durable_activities` in
`messenger.yaml` does not select it: without `type: messenger` the transport stays empty and the
activity has already run inline, taking the workflow task's time with it and losing the retry
semantics the transport provides.

---

## `messenger`

```yaml
durable:
    messenger:
        buses:
            - messenger.bus.durable
```

Which Messenger buses the bundle installs its middlewares on — the DBAL resume lock, and the
profiler middleware in debug.

**The default is every bus**, which is what earlier versions did unconditionally. That default
cannot be narrower: the bundle does not know which bus your application routes
`ResumeWorkflowMessage` to, and guessing would take the resume lock off the bus that carries the
work — a silent loss of the guarantee the lock exists to give.

Naming buses is worth doing once you have more than one. A business command bus carries no durable
message, and taking a per-execution lock on it is contention nobody asked for. An id that names no
declared bus is refused at compile time rather than silently doing nothing.

---

## `max_activity_retries`

```yaml
durable:
    max_activity_retries: 3
```

Ceiling on automatic retries, applied to activities that do not set their own. `0` means **no ceiling** — and since an activity with no `RetryLimit` retries indefinitely (Temporal's default), leaving both unset means a failing activity never fails the workflow. Set a bound per activity with `RetryLimit::ofAttempts()` or `RetryLimit::once()`; see [Options and value objects](../options/#retrylimit).

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
| `parent_link_store.type` | `in_memory`, `dbal` | `in_memory` | Tracks parent→child links for completion propagation. `dbal` persists them in SQL. |
| `parent_link_store.table_name` | string | `durable_child_workflow_parent_link` | Table the `dbal` store writes to. Created on first write. |

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
