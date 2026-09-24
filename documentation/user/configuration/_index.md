---
title: Configuration reference
weight: 35
---

# Configuration reference

This page documents every key accepted by `DurableBundle` in `config/packages/durable.yaml`.

---

## Every key and its default {#full-example}

Generated from the bundle's configuration tree by `bin/console config:dump-reference durable`; a
test in the Symfony bench fails when this block and the tree disagree. The sections below say what
each key is for.

<!-- generated: bin/console config:dump-reference durable -->
```yaml
# Default configuration for extension with alias: "durable"
durable:

    # Where the journal lives. in_memory: one process. dbal: a SQL database (DUR030). temporal: the cluster at temporal.dsn. dbal with a temporal.dsn keeps the journal in SQL and uses the cluster to serve Nexus. Derived from the deprecated event_store.type and temporal.journal when unset.
    backend:              null # One of "in_memory"; "dbal"; "temporal"

    # DBAL backend: durable execution on a single SQL database, with no orchestration cluster (DUR030).
    dbal:

        # Service id of the Doctrine\DBAL\Connection to use
        connection:           doctrine.dbal.default_connection

        # Create the missing tables on the first write, never inside an open transaction. Set it to false as soon as doctrine/migrations holds the schema: otherwise the two mechanisms write one behind the other. bin/console durable:setup creates them either way.
        auto_setup:           true

        # Service id of the Symfony\Component\Lock\LockFactory that serialises the resumes of one execution
        lock_factory:         lock.factory

        # Seconds a resume lock outlives a worker that died holding it. The pass refreshes it at every message it sends through the bus, so it must exceed the longest step of a pass, or a second worker replays the same execution in parallel.
        lock_ttl:             300.0
    event_store:
        type:                 null # One of "in_memory"; "dbal", Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.event_store.type" option is deprecated: set durable.backend instead.)

        # Table of the dbal journal.
        table_name:           durable_events
    temporal:

        # A temporal://… DSN (for instance %env(DURABLE_DSN)%). When set, it turns on the native Temporal backend (gRPC); over ext-grpc when it is loaded, over curl (ext-curl) otherwise; temporal+http:// for the JSON gateway. No SQL/PDO.
        dsn:                  null

        # A service id: the application's GuzzleHttp\ClientInterface, which transport=guzzle then uses — its proxy, TLS options and middleware apply to gRPC. Unused by any other transport; null builds a default client.
        guzzle_client:        null

        # A service id: the application's PSR-18 client, which transport=http (the JSON gateway) then uses instead of curl. Unused by any other transport.
        psr18_client:         null

        # A service id implementing both PSR-17 RequestFactoryInterface and StreamFactoryInterface (Guzzle's HttpFactory, nyholm's Psr17Factory). Defaults to psr18_client, which Symfony's Psr18Client satisfies on its own.
        psr17_factory:        null

        # false: the cluster is reachable, but the journal stays the one in event_store. An application serving a Nexus operation from a DBAL journal needs both — and there are not two sources of truth, since event_store says which one it is.
        journal:              null # Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.temporal.journal" option is deprecated: set durable.backend instead.)
    activity_transport:

        # in_memory runs activities inside the workflow task; messenger routes them to transport_name.
        type:                 in_memory # One of "in_memory"; "messenger"
        table_name:           durable_activity_outbox # Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.activity_transport.table_name" option is read nowhere: no outbox table exists. Remove it.)

        # The Messenger transport activities go to when type is messenger.
        transport_name:       durable_activities
    messenger:

        # Ids of the Messenger buses the bundle installs its middleware on (resume lock, profiler). Empty, which is the default, installs them on every bus, and that is the historical behaviour. Naming buses avoids imposing a per-execution lock on the business command bus, which carries no durable message.
        buses:                []
    profiler:

        # Registers the execution trace, the web profiler panel and the observer on the hot path. Defaults to kernel.debug.
        enabled:              '%kernel.debug%'

    # Retry ceiling for activities that set none. 0: no ceiling.
    max_activity_retries: 0
    activity_contracts:

        # PSR-6 cache pool ID for activity contract metadata
        cache:                null

        # Class names of activity contracts to warm at cache warmup
        contracts:            []
    child_workflow:

        # true: child workflows are dispatched through Messenger; false: they run inside the parent task.
        async_messenger:      false
        parent_link_store:
            type:                 null # One of "in_memory"; "dbal", Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.child_workflow.parent_link_store.type" option is deprecated: set durable.backend instead.)

            # Table of the dbal parent links.
            table_name:           durable_child_workflow_parent_link
    workflow_metadata:
        type:                 null # One of "in_memory"; "dbal", Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.workflow_metadata.type" option is deprecated: set durable.backend instead.)

        # Table of the dbal workflow metadata.
        table_name:           durable_workflow_metadata
```
<!-- end generated -->

> [!IMPORTANT]
> **`activity_transport.type` defaults to `in_memory`, not `messenger`.** Omit the key and activities
> run **synchronously inside the workflow task**, whatever transport `messenger.yaml` defines. Every
> example on this site sets it explicitly for that reason. See
> [`activity_transport`](#activity_transport).

---

## `backend`

Where the journal lives. One key, because the journal, the workflow metadata and the parent links
have to agree: two sources of truth for one run is the failure this key rules out.

| Value | Journal, metadata, parent links | Needs |
|-------|---------------------------------|-------|
| `in_memory` (default) | the PHP process | nothing; tests and single-process demos |
| `dbal` | SQL, through [`dbal`](#dbal) | a Doctrine DBAL connection and a shared lock store |
| `temporal` | the cluster at [`temporal.dsn`](#temporal); the process keeps only the copy of the metadata and parent links that the profiler and `durable:execution:diagnose` read | `temporal.dsn` |

`dbal` with a `temporal.dsn` keeps the journal in SQL and uses the cluster only to serve Nexus
operations; see [Nexus operations](../nexus/).

A configuration that contradicts itself is refused when the container is built, with the
`durable` path in the message: `backend: temporal` without a DSN, or a deprecated key below that
says otherwise than `backend`.

> [!NOTE]
> `event_store.type`, `workflow_metadata.type`, `child_workflow.parent_link_store.type` and
> `temporal.journal` are deprecated since 0.1.0-beta1 and will be removed in the next version. When
> `backend` is not set, it is derived from them, so an existing configuration keeps working and
> reports a deprecation. [UPGRADE.md](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md)
> has the translation.

---

## `dbal`

Where the SQL backend gets its connection and its lock. Read only when `backend` is `dbal`; ignored
otherwise, so it costs nothing to leave at its defaults.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `connection` | service ID | `doctrine.dbal.default_connection` | The `Doctrine\DBAL\Connection` the stores write to. |
| `auto_setup` | bool | `true` | Creates the missing tables on the first write, never inside an open transaction. Set it to `false` once Doctrine Migrations owns the schema, so that the two do not both write it. `bin/console durable:setup` creates the tables either way. |
| `lock_factory` | service ID | `lock.factory` | The `LockFactory` that serialises resumes of one execution. **It is only as safe as your lock store**: an in-memory or per-process factory with several workers gives back the failure the lock exists to prevent. |
| `lock_ttl` | float, seconds | `300` | How long a resume lock outlives a worker that died holding it. The pass that holds it gives it a fresh TTL at every step boundary, that is every message it sends through the bus, so **it must exceed the longest step**: past it, a second worker replays the same execution in parallel, and the first one stops at its next boundary. A step is usually the replay of the journal up to the next command, well under a second. Activities run outside the pass, except on a `sync://` activity transport, where each one is a step of the pass and its duration counts. A pass that only replays, with no message in between, is not refreshed. |

The trade this backend makes, and why the lock is load-bearing, are on the
[Backends](../backends/#dbal-backend) page.

---

## `event_store`

Controls where workflow event history is stored.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `in_memory`, `dbal` | from `backend` | **Deprecated**: set [`backend`](#backend). |
| `table_name` | string | `durable_events` | Table the `dbal` store writes to. Created on first write. |

### When using Temporal

With `backend: temporal`, the local event store is in memory, and that is correct. `TemporalReadThroughEventStore` wraps it: events missing locally are fetched from Temporal gRPC (`GetWorkflowExecutionHistory`) on demand, so the Symfony profiler DataCollector works across processes.

---

## `temporal`

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `dsn` | `temporal://host:port?…` or `null` | `null` | The cluster. Required by `backend: temporal`; with `backend: dbal`, the cluster serves Nexus operations and the journal stays in SQL. Anything but a non-empty string or `null` is refused. gRPC goes through `ext-grpc` when the extension is loaded and through curl (HTTP/2) otherwise; the scheme picks the wire, see below. |
| `journal` | `true` / `false` | from `backend` | **Deprecated**: `true` is `backend: temporal`, `false` with a DSN is `backend: dbal`. |
| `guzzle_client` | a service id or `null` | `null` | The application's `GuzzleHttp\ClientInterface`, used by `transport=guzzle` in the DSN: its proxy, TLS options and middleware apply to gRPC. Ignored by any other transport; `null` builds a default client. On Laravel, the same key in `config/durable.php` names a container binding; on Magento, it is the `guzzle` argument of `RuntimeFactory` in `di.xml`. |
| `psr18_client` | a service id or `null` | `null` | The application's PSR-18 client, used by `transport=http` (the JSON gateway) instead of curl. Ignored by any other transport. |
| `psr17_factory` | a service id or `null` | `psr18_client` | One service implementing both PSR-17 request and stream factories — Guzzle's `HttpFactory`, nyholm's `Psr17Factory`. Symfony's `Psr18Client` is both client and factory, hence the default. On Laravel, both keys in `config/durable.php` name container bindings; on Magento, a `Psr18Http` is the `jsonGateway` argument of `RuntimeFactory` in `di.xml`. |

### DSN format

```
temporal://HOST:PORT?namespace=NAMESPACE&journal_task_queue=QUEUE&activity_task_queue=QUEUE
```

The scheme names the wire and the encryption:

| Scheme | Wire | TLS | Default port | Needs |
|--------|------|-----|--------------|-------|
| `temporal://` | gRPC | no | 7233 | `ext-grpc`, or `ext-curl` (gRPC over HTTP/2, chosen automatically when the extension is not loaded; the fallback is logged once) |
| `temporal+tls://` | gRPC | yes | 7233 | same |
| `temporal+http://` | the server JSON gateway | no | 7243 | `ext-curl` — or a PSR-18 client handed to the factory — and the HTTP port enabled on the server. Client calls only: no worker can poll through it |
| `temporal+https://` | the server JSON gateway | yes | 7243 | same |

| Parameter | Required | Description |
|-----------|----------|-------------|
| `namespace` | yes | Temporal namespace (e.g. `default`). |
| `journal_task_queue` | yes | Task queue for workflow tasks (e.g. `durable-journal`). |
| `activity_task_queue` | yes | Task queue for activity tasks (e.g. `durable-activities`). |
| `tls` | no | `tls=1` is the older spelling of the `+tls` and `+https` schemes; still accepted. |
| `transport` | no (default `auto`) | Overrides what the scheme implies: `grpc` demands `ext-grpc` and fails without it, `grpc-curl` forces curl even when the extension is loaded, `guzzle` sends gRPC through Guzzle 7.14 or newer (its cURL handler reads the trailers), `http` is what `temporal+http://` sets. `auto` picks `grpc` when the extension is loaded and `grpc-curl` otherwise. |

**Example:**
```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities
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
| `type` | `in_memory`, `dbal` | from `backend` | **Deprecated**: set [`backend`](#backend). |
| `table_name` | string | `durable_workflow_metadata` | Table the `dbal` store writes to. Created on first write. |

---

## `activity_transport`

How the bundle dispatches activity messages from workflow tasks to activity handlers.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `in_memory`, `messenger` | **`in_memory`** | `in_memory` executes activities **synchronously within the workflow task handler**, which is what you get when the key is absent. `messenger` routes activity messages via Symfony Messenger to the configured transport. |
| `transport_name` | string | `durable_activities` | Name of the Messenger transport used when `type: messenger`. Must match a transport defined in `messenger.yaml`. |
| `table_name` | string | `durable_activity_outbox` | **Deprecated**, read nowhere: no outbox table exists. Remove it. |

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

## `profiler`

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | bool | `%kernel.debug%` | Registers the execution trace, the web profiler panel and the observer on the execution's hot path. Off, the observer is a null object. |

---

## `max_activity_retries`

```yaml
durable:
    max_activity_retries: 3
```

Ceiling on automatic retries, applied to activities that do not set their own. A negative value is refused. `0` means **no ceiling**, and since an activity with no `RetryLimit` retries indefinitely (Temporal's default), leaving both unset means a failing activity never fails the workflow. Set a bound per activity with `RetryLimit::ofAttempts()` or `RetryLimit::once()`; see [Options and value objects](../options/#retrylimit).

---

## `activity_contracts`

Pre-resolved activity contract metadata (method names, attributes) can be cached at container warm-up to avoid reflection overhead at runtime.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `cache` | string (service ID) or `null` | `null` | PSR-6 cache pool to use. `cache.app` is the Symfony default pool. Set `null` to disable caching (useful in `test` environment). |
| `contracts` | list of FQCN strings | `[]` | Activity contract interfaces to warm up. A name the autoloader cannot find as an interface is refused when the container is built. |

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
| `parent_link_store.type` | `in_memory`, `dbal` | from `backend` | **Deprecated**: set [`backend`](#backend). |
| `parent_link_store.table_name` | string | `durable_child_workflow_parent_link` | Table the `dbal` store writes to. Created on first write. |

---

## Environment-specific configuration (`when@`)

Use Symfony's `when@` syntax to change backends per environment:

```yaml
# In-Memory for every environment not overridden below
durable:
    backend: in_memory

# Temporal for dev and prod
when@dev:
    durable:
        backend: temporal
        temporal:
            dsn: '%env(DURABLE_DSN)%'

when@prod:
    durable:
        backend: temporal
        temporal:
            dsn: '%env(DURABLE_DSN)%'

# In-Memory for tests, even if DURABLE_DSN is set
when@test:
    durable:
        child_workflow:
            async_messenger: false
```

---

## See also

- [Backends](../backends/) compares In-Memory and Temporal: Docker setup, workers, DSN parameters.
- [Getting started](../getting-started/) covers Messenger routing configuration.
- [Testing workflows](../testing/) covers `DurableBundleTestTrait` and in-memory test configuration.
