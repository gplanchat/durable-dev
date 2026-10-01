---
title: Configuration reference
weight: 35
---

# Configuration reference

This page documents every key that `DurableBundle` accepts in `config/packages/durable.yaml`.

Terms used below: an execution is one durable run of a workflow; the journal is the append-only
record of everything an execution decided and received; an activity is a unit of side effect, such
as an HTTP call or a database write; a worker is the process that replays workflows and runs
activities; replay is how an execution resumes, by running the workflow code again from its first
line against the journal. The [glossary](../glossary/) defines each of them.

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

        # Accept a per-process lock store (flock, semaphore, in-memory). Only safe with exactly one worker: two workers holding a local lock replay the same execution at once.
        allow_local_lock:     false

        # Seconds a resume lock outlives a worker that died holding it. The pass refreshes it at every message it sends through the bus, so it must exceed the longest step of a pass, or a second worker replays the same execution in parallel.
        lock_ttl:             300.0
    event_store:
        type:                 null # One of "in_memory"; "dbal", Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.event_store.type" option is deprecated: set durable.backend instead.)

        # Table of the dbal journal.
        table_name:           durable_events
    temporal:

        # A temporal://… DSN (for instance %env(DURABLE_DSN)%). When set, it turns on the native Temporal backend (gRPC); over ext-grpc when it is loaded, over curl (ext-curl) otherwise; temporal+http:// for the JSON gateway. No SQL/PDO.
        dsn:                  null

        # Write the DurableWorkflowName and DurableExecutionId search attributes on every start, so the run list can filter by workflow name and execution id. Register both on the namespace before turning this on: a server refuses a start that names an unregistered attribute.
        search_attributes:    false

        # A service id: the application's GuzzleHttp\ClientInterface, which transport=guzzle then uses — its proxy, TLS options and middleware apply to gRPC. Unused by any other transport; null builds a default client.
        guzzle_client:        null

        # A service id: the application's PSR-18 client, which transport=http (the JSON gateway) then uses instead of curl. Unused by any other transport.
        psr18_client:         null

        # A service id implementing both PSR-17 RequestFactoryInterface and StreamFactoryInterface (Guzzle's HttpFactory, nyholm's Psr17Factory). Defaults to psr18_client, which Symfony's Psr18Client satisfies on its own.
        psr17_factory:        null

        # A service id: the application's PayloadCodecInterface, which encodes every payload sent to Temporal and decodes every payload read (DUR055). The codec holds its own key, from the application's secrets or environment; Durable reads none. null sends payloads as they are.
        payload_codec:        null

        # false: the cluster is reachable, but the journal stays the one in event_store. An application serving a Nexus operation from a DBAL journal needs both — and there are not two sources of truth, since event_store says which one it is.
        journal:              null # Deprecated (Since gplanchat/durable-bundle 0.1.0-beta1: The "durable.temporal.journal" option is deprecated: set durable.backend instead.)
    activity_transport:

        # in_memory runs activities inside the workflow task; messenger routes them to transport_name.
        type:                 in_memory # One of "in_memory"; "messenger"

        # The Messenger transport activities go to when type is messenger.
        transport_name:       durable_activities
    messenger:

        # Ids of the Messenger buses the bundle installs its middleware on (resume lock, profiler). Empty, which is the default, installs them on every bus, and that is the historical behaviour. Naming buses avoids imposing a per-execution lock on the business command bus, which carries no durable message.
        buses:                []
    profiler:

        # Registers the execution trace, the web profiler panel and the observer on the hot path. Defaults to kernel.debug.
        enabled:              '%kernel.debug%'

    # Retry ceiling for every activity; an activity's own limit can only be stricter. 0: no ceiling.
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
> **The default value of `activity_transport.type` is `in_memory`.** When you omit the key,
> activities run **synchronously inside the workflow task**, whatever transport `messenger.yaml`
> defines. For that reason, every example on this site sets the key explicitly. See
> [`activity_transport`](#activity_transport).

---

## `backend`

Where the journal lives. A single key sets the storage of the journal, the workflow metadata and
the parent links, so that the three agree and one run never has two sources of truth.

| Value | Journal, metadata, parent links | Needs |
|-------|---------------------------------|-------|
| `in_memory` (default) | the PHP process | nothing; tests and single-process demos |
| `dbal` | SQL, through [`dbal`](#dbal) | a Doctrine DBAL connection and a shared lock store |
| `temporal` | the cluster at [`temporal.dsn`](#temporal); the process keeps only the copy of the metadata and parent links that the profiler and `durable:execution:diagnose` read | `temporal.dsn` |

`dbal` with a `temporal.dsn` keeps the journal in SQL and uses the cluster only to serve Nexus
operations (operations served by another service, which a workflow calls the way it calls an
activity); see [Nexus operations](../nexus/).

When the configuration contradicts itself, the container build fails with the `durable` path in the
message. Two cases trigger it: `backend: temporal` without a DSN, and a deprecated key below that
says otherwise than `backend`.

> [!NOTE]
> `event_store.type`, `workflow_metadata.type`, `child_workflow.parent_link_store.type` and
> `temporal.journal` are deprecated since 0.1.0-beta1 and will be removed in the next version. When
> `backend` is not set, the bundle derives it from them, so an existing configuration keeps working
> and reports a deprecation. [UPGRADE.md](https://github.com/gplanchat/durable-dev/blob/main/UPGRADE.md)
> gives the translation.

---

## `dbal`

The connection and the lock of the SQL backend. The bundle reads this section only when `backend` is
`dbal` and ignores it otherwise, so leaving it at its defaults costs nothing.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `connection` | service ID | `doctrine.dbal.default_connection` | The `Doctrine\DBAL\Connection` the stores write to. Give them a connection of their own. Sharing the application's connection is strongly discouraged (DUR054), because Durable's transactions then nest inside business ones. |
| `auto_setup` | bool | `true` | Creates the missing tables on the first write, never inside an open transaction. Set it to `false` once Doctrine Migrations owns the schema, so that only one of the two writes it. `bin/console durable:setup` creates the tables either way. |
| `lock_factory` | service ID | `lock.factory` | The `LockFactory` that serialises resumes of one execution. **The lock protects resumes only if the lock store is shared by every worker.** With several workers, an in-memory or per-process factory lets two workers replay the same execution at once, which is the failure the lock prevents. |
| `allow_local_lock` | bool | `false` | With `false`, a per-process store (`flock`, `semaphore`, `in-memory`, `null`) behind `lock_factory` raises an error: at compile time for a literal DSN, and when the lock is first built for a DSN read from an environment variable. `true` accepts such a store, for exactly one worker. `framework.lock` takes a DBAL URL (`pgsql://…`, `mysql://…`); it does not accept a Doctrine connection name. |
| `lock_ttl` | float, seconds | `300` | How long a resume lock outlives a worker that died holding it. The pass that holds the lock gives it a fresh TTL at every step boundary, that is at every message it sends through the bus, so **the TTL must exceed the longest step**. When a step outlasts it, a second worker replays the same execution in parallel, and the first one stops at its next boundary. A step is usually the replay of the journal up to the next command, well under a second. Activities run outside the pass, except on a `sync://` activity transport, where each one is a step of the pass and its duration counts. A pass that only replays, with no message in between, is not refreshed. |

The [Backends](../backends/#dbal-backend) page describes the trade-off this backend makes and why
it depends on the lock.

---

## `event_store`

Where the workflow event history is stored.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `in_memory`, `dbal` | from `backend` | **Deprecated**: set [`backend`](#backend). |
| `table_name` | string | `durable_events` | Table the `dbal` store writes to. Created on first write. |

### Local event store on the Temporal backend {#when-using-temporal}

With `backend: temporal`, the local event store is in memory, which is the expected setup.
`TemporalReadThroughEventStore` wraps it and fetches the events missing locally from Temporal over
gRPC (`GetWorkflowExecutionHistory`) on demand, so the Symfony profiler DataCollector works across
processes.

---

## `temporal`

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `dsn` | `temporal://host:port?…` or `null` | `null` | The cluster. Required by `backend: temporal`; with `backend: dbal`, the cluster serves Nexus operations and the journal stays in SQL. Any value other than a non-empty string or `null` raises a configuration error. gRPC goes through `ext-grpc` when the extension is loaded and through curl (HTTP/2) otherwise; the scheme picks the wire, see below. |
| `journal` | `true` / `false` | from `backend` | **Deprecated**: `true` is `backend: temporal`, `false` with a DSN is `backend: dbal`. |
| `search_attributes` | `true` / `false` | `false` | Writes `DurableWorkflowName` and `DurableExecutionId` on every start, so the run list can filter by workflow name and execution id. [Register them on the namespace](../backends/#register-durables-search-attributes) **before** turning this on. On Laravel, the same key in `config/durable.php`; on Magento, `durable/temporal/search_attributes` in `env.php`. |
| `guzzle_client` | a service id or `null` | `null` | The application's `GuzzleHttp\ClientInterface`, used by `transport=guzzle` in the DSN: its proxy, TLS options and middleware apply to gRPC. Ignored by any other transport; `null` builds a default client. On Laravel, the same key in `config/durable.php` names a container binding; on Magento, it is the `guzzle` argument of `RuntimeFactory` in `di.xml`. |
| `psr18_client` | a service id or `null` | `null` | The application's PSR-18 client, used by `transport=http` (the JSON gateway) instead of curl. Ignored by any other transport. |
| `psr17_factory` | a service id or `null` | `psr18_client` | One service implementing both PSR-17 request and stream factories, such as Guzzle's `HttpFactory` or nyholm's `Psr17Factory`. Symfony's `Psr18Client` is both client and factory, hence the default. On Laravel, both keys in `config/durable.php` name container bindings; on Magento, a `Psr18Http` is the `jsonGateway` argument of `RuntimeFactory` in `di.xml`. |
| `payload_codec` | a service id or `null` | `null` | The application's `PayloadCodecInterface`, which encodes every payload sent to Temporal and decodes every payload read ([DUR055](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR055-a-payload-codec-at-the-client-boundary.md)). The codec reads its own key, from Symfony secrets or the environment; Durable reads none. |

### DSN format

```
temporal://HOST:PORT?namespace=NAMESPACE&journal_task_queue=QUEUE&activity_task_queue=QUEUE
```

The scheme sets the wire protocol and the encryption:

| Scheme | Wire | TLS | Default port | Needs |
|--------|------|-----|--------------|-------|
| `temporal://` | gRPC | no | 7233 | `ext-grpc`, or `ext-curl` (gRPC over HTTP/2, chosen automatically when the extension is not loaded; the fallback is logged once) |
| `temporal+tls://` | gRPC | yes | 7233 | same |
| `temporal+http://` | the server JSON gateway | no | 7243 | `ext-curl` (or a PSR-18 client handed to the factory) and the HTTP port enabled on the server. Client calls only; no worker can poll through it |
| `temporal+https://` | the server JSON gateway | yes | 7243 | same |

| Parameter | Required | Description |
|-----------|----------|-------------|
| `namespace` | yes | Temporal namespace (e.g. `default`). |
| `journal_task_queue` | yes | Task queue for workflow tasks (e.g. `durable-journal`). |
| `activity_task_queue` | yes | Task queue for activity tasks (e.g. `durable-activities`). |
| `task_queue` | no | The older spelling of `journal_task_queue`, read when that one is absent. |
| `workflow_task_queue` | no (default `durable-workflows`) | Task queue for the application's workflow tasks. |
| `nexus_task_queue` | no (default: the workflow task queue) | Task queue for the Nexus tasks this application serves. |
| `identity` | no (default `durable-temporal-bridge-php`) | Identity this worker reports to the server. |
| `tls` | no | `tls=1` is the older spelling of the `+tls` and `+https` schemes; still accepted. |
| `ca` | no, TLS only | Path to the PEM file of the CA that signs the server certificate. Without it, the system store is trusted. |
| `cert` | no, TLS only | Path to the PEM file of a client certificate, for mTLS. Needs `key`. |
| `key` | no, TLS only | Path to the PEM file of that certificate's private key. Needs `cert`. |
| `api_key` | no, TLS only | Sent with every call as `authorization: Bearer …`, beside a `temporal-namespace` header (Temporal Cloud API keys). URL-encode it. |
| `transport` | no (default `auto`) | Overrides what the scheme implies: `grpc` demands `ext-grpc` and fails without it, `grpc-curl` forces curl even when the extension is loaded, `guzzle` sends gRPC through Guzzle 7.14 or newer (its cURL handler reads the trailers), `http` is what `temporal+http://` sets. `auto` picks `grpc` when the extension is loaded and `grpc-curl` otherwise. |

Any other parameter raises an error that names it, so a typo such as `namesapce=` no longer falls
back to the `default` namespace in silence. `ca`, `cert`, `key` or `api_key` without TLS raise an
error too. With a PSR-18 client handed to the JSON gateway, TLS is that client's own configuration,
and `ca`, `cert` and `key` raise an error.

**Example:**
```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities
```

To read the DSN from an environment variable:
```yaml
durable:
    temporal:
        dsn: '%env(DURABLE_DSN)%'
```

---

## `workflow_metadata`

Where the workflow type and initial payload are stored. The bundle looks them up by `executionId`
when it resumes an execution.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `in_memory`, `dbal` | from `backend` | **Deprecated**: set [`backend`](#backend). |
| `table_name` | string | `durable_workflow_metadata` | Table the `dbal` store writes to. Created on first write. |

---

## `activity_transport`

How the bundle dispatches activity messages from workflow tasks to activity handlers.

| Key | Values | Default | Description |
|-----|--------|---------|-------------|
| `type` | `in_memory`, `messenger` | **`in_memory`** | `in_memory` executes activities **synchronously within the workflow task handler**, which is what you get when the key is absent. `messenger` routes activity messages through Symfony Messenger to the configured transport. |
| `transport_name` | string | `durable_activities` | Name of the Messenger transport used when `type: messenger`. Must match a transport defined in `messenger.yaml`. |

**In production, you probably do not want the default.** Defining `durable_activities` in
`messenger.yaml` does not select it. Without `type: messenger`, the transport stays empty and the
activity runs inline, within the workflow task's time and without the retry semantics the transport
provides.

---

## `messenger`

```yaml
durable:
    messenger:
        buses:
            - messenger.bus.durable
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `buses` | list of bus service IDs | `[]` | The buses the bundle's middlewares go on; `[]` means every bus. See below. |

The Messenger buses the bundle installs its middlewares on: the DBAL resume lock, and the profiler
middleware in debug.

**The default is every bus**, which earlier versions did unconditionally. A narrower default is not
possible, because nothing tells the bundle which bus your application routes
`ResumeWorkflowMessage` to. A wrong choice would take the resume lock off the bus that carries the
work, and resumes would lose the lock's protection without any error.

Naming the buses is worth doing once your application has more than one. A business command bus
carries no durable message, and a per-execution lock on it adds contention for nothing. An id that
names no declared bus raises an error at compile time instead of silently doing nothing.

---

## `profiler`

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | bool | `%kernel.debug%` | Registers the execution trace, the web profiler panel and the observer on the execution's hot path. Off, the observer is a null object. |

The panel shows what each traced execution waits on, read from the run catalog. On Temporal, this
costs one `DescribeWorkflowExecution` call per execution in the profiled request, two for a run Durable did
not start.

---

## `max_activity_retries`

```yaml
durable:
    max_activity_retries: 3
```

Ceiling on automatic retries, applied to every activity on the `in_memory` and `dbal` backends: an activity's own `RetryLimit` can only be stricter. A negative value raises a configuration error. `0` means **no ceiling**, and since an activity with no `RetryLimit` retries indefinitely (Temporal's default), leaving both unset means a failing activity never fails the workflow. Set a bound per activity with `RetryLimit::ofAttempts()` or `RetryLimit::once()`; see [Options and value objects](../options/#retrylimit). On the `temporal` backend no host reads it: the cluster retries from each activity's own `RetryLimit`.

On Laravel, the same key in `config/durable.php`; on Magento, the `maxActivityRetries` argument of
`RuntimeFactory` in `di.xml`, which only `MagentoRuntime::run()` reads, and only without a DSN (see
[the host table](#host-table)).

---

## `activity_contracts`

The bundle can cache pre-resolved activity contract metadata (method names, attributes) at container
warm-up, which avoids the reflection overhead at runtime.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `cache` | string (service ID) or `null` | `null` | PSR-6 cache pool to use. `cache.app` is the Symfony default pool. Set `null` to disable caching (useful in `test` environment). |
| `contracts` | list of FQCN strings | `[]` | Activity contract interfaces to warm up. A name the autoloader cannot find as an interface makes the container build fail. |

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

How child workflows, executions started by another execution, are dispatched.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `async_messenger` | bool | `false` | When `true`, child workflow runs are dispatched via Messenger (async). When `false`, they run synchronously within the parent workflow task. |
| `parent_link_store.type` | `in_memory`, `dbal` | from `backend` | **Deprecated**: set [`backend`](#backend). |
| `parent_link_store.table_name` | string | `durable_child_workflow_parent_link` | Table the `dbal` store writes to. Created on first write. |

---

## Environment-specific configuration (`when@`)

To change the backend per environment, use Symfony's `when@` syntax:

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

## The same settings on Laravel and Magento {#host-table}

One row per setting. The last column is a **proposal** under review (#357): *same* (the setting
exists on each host that can use it), *host-specific* (with the reason), or *to add*. Magento
reaches two journals only, in memory and Temporal, so the SQL rows do not apply there.

| Symfony (`durable.yaml`) | Laravel (`config/durable.php`) | Magento (`env.php`, `di.xml`) | Proposal |
|---|---|---|---|
| `backend` | `backend` (`illuminate`, `temporal`, `memory`) | a DSN means Temporal, none means in memory: the `temporalDsn` argument in `di.xml`, else `durable/temporal/dsn` in `env.php` | same; the SQL value is named after each host's connection |
| `dbal.connection` | `connection` | none | same |
| `dbal.auto_setup` | none (the bridge ships migrations) | none | host-specific: Laravel creates tables with `php artisan migrate` |
| `dbal.lock_factory`, `dbal.allow_local_lock` | `lock.store` | none | host-specific: Symfony Lock and Laravel's cache locks are different services |
| `dbal.lock_ttl` | `lock.ttl` | none | same |
| none | `lock.backoff`, `lock.max_deferrals`, `lock.wait` | none | host-specific: Laravel hands a resume whose turn is taken back to the queue; the Symfony worker blocks until the lock frees |
| `event_store.table_name`, `workflow_metadata.table_name`, `child_workflow.parent_link_store.table_name` | `tables.events`, `tables.metadata`, `tables.parent_links`, `tables.runs` | none | to add: the runs table name on Symfony |
| `temporal.dsn` | `temporal.dsn` | `temporalDsn` argument, which wins over `durable/temporal/dsn` | same |
| `temporal.search_attributes` | `temporal.search_attributes` | `durable/temporal/search_attributes` | same |
| `temporal.guzzle_client`, `temporal.psr18_client`, `temporal.psr17_factory` | the same three keys | `guzzle`, `jsonGateway` arguments | same |
| `temporal.payload_codec` | `temporal.payload_codec`, a container binding; the codec reads its key from `.env` | `codec` argument of `RuntimeFactory`, in the shop's own `di.xml`; the codec reads its key from `env.php` | same (DUR055) |
| `backend: dbal` with a `temporal.dsn` (serve Nexus from a SQL journal) | none (`nexus.handlers` requires `backend: temporal`) | none | to add on Laravel |
| `activity_transport.type`, `activity_transport.transport_name` | `queue.connection`, `queue.name` | none (activities run in the process, or on Temporal's task queue) | host-specific: each host's own queue |
| `messenger.buses` | none | none | host-specific: Messenger only |
| `profiler.enabled` | none | none | host-specific: the Symfony web profiler |
| `max_activity_retries` | `max_activity_retries` | `maxActivityRetries` argument, read by `MagentoRuntime::run()` without a DSN only; Temporal workers ignore it | same on Symfony and Laravel; host-specific on Magento, whose workers leave retries to the cluster. On Temporal, no host reads it |
| none | none | `budgetSeconds` argument | host-specific: bounds `MagentoRuntime::run()`, the in-process run without a DSN and the wait for the cluster's result with one |
| `activity_contracts.cache`, `activity_contracts.contracts` | none | none | to add on Laravel and Magento |
| `child_workflow.async_messenger` | none | none | host-specific: Messenger only |
| workflows: `#[AsWorkflow]` on a service | `workflows` | `workflowClasses` argument | host-specific: neither container autoconfigures by attribute |
| activity handlers: `#[AsActivityHandler]` on a service | `activity_handlers`: the handler classes, each serving the contract its `#[AsActivityHandler]` names, or else its interfaces with `#[AsActivityMethod]` methods | `activityHandlers` argument | host-specific: neither container autoconfigures by attribute; Laravel fails at boot on a handler that serves no activity |
| Nexus handlers: `#[AsNexusServiceHandler]` on a service | `nexus.handlers`: `handler => contract`, or the handler class alone when its `#[AsNexusServiceHandler]` names the contract | `nexusHandlers` argument; the handler's `#[AsNexusServiceHandler]` names the contract | host-specific: neither container autoconfigures by attribute |

---

## See also

- [Backends](../backends/) compares In-Memory and Temporal: Docker setup, workers, DSN parameters.
- [Getting started](../getting-started/) covers Messenger routing configuration.
- [Testing workflows](../testing/) covers `DurableBundleTestTrait` and in-memory test configuration.
