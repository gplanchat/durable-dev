---
title: Backends
weight: 15
---

# Backends

Durable has four execution backends. A backend is where the journal lives and what schedules the
work; the journal is the append-only record of everything an execution decided and received (see
the [glossary](../glossary/)). One backend is In-Memory; the other three are the bridges you choose
between.

| Backend | Use case |
|---------|----------|
| **In-Memory** | Unit tests, functional tests, local exploration; no server needed. |
| **DBAL** | Production without an orchestration cluster: one SQL database, no `ext-grpc`. |
| **Illuminate** | The same, on Laravel's connection rather than Doctrine's. |
| **Temporal** | Production and staging at scale, realistic integration tests; `ext-grpc` and a Temporal cluster required. |

> [!NOTE]
> **On Magento, the two SQL backends are not available.** `gplanchat/durable-magento` declares a
> Composer `conflict` on both SQL bridges: `Magento\Framework\App\ResourceConnection` is neither
> Doctrine DBAL nor Illuminate's connection, so neither bridge has anything to bind to. The state
> lives either in a Temporal cluster or in one process. The presence of `durable/temporal/dsn` in
> `app/etc/env.php` selects between the two; no setting does.

All four run the **same fiber driver** and the same workflow and activity code. You select three
of them with `durable.backend` (and `DURABLE_DSN` for Temporal). **Illuminate is not one of its
values** and never will be; [the Illuminate backend](#illuminate-backend) describes what binds it
instead.

---

## In-Memory backend

The In-Memory backend runs entirely inside a single PHP process. It needs no external server and
no gRPC, and nothing persists between requests.

### How it works

- Workflow and activity messages are dispatched through **Symfony Messenger** in-memory transports.
- The event history lives in an `InMemoryEventStore`.
- The Messenger drain processes messages synchronously when you call `drainMessengerUntilSettled()` or the equivalent.

### Configuration

```yaml
# config/packages/durable.yaml (or when@test:)
durable:
    backend: in_memory
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
3. The `durable_workflows` worker (a process that pulls work; see the [glossary](../glossary/))
   polls **workflow tasks**.
4. The `durable_activities` worker polls **activity tasks**.
5. Each workflow task replays history with the fiber-based `WorkflowTaskRunner` and sends commands
   back to Temporal. Replay runs the workflow code again from its first line and answers each
   recorded step from the journal.

The bundle registers these workers itself, from `durable.temporal.dsn`: `messenger:consume` finds
them by name, and `messenger.yaml` declares no Temporal transport. A third one, `durable_nexus`,
exists when the application [serves a Nexus operation](../nexus/).

### Waiting for the result with `pollForCompletion()` {#waiting-for-the-result}

`WorkflowClient::pollForCompletion()` reads the run's close event until it arrives, then returns the
result or throws. Two things differ from a run on the journal backends:

- A workflow that lets an activity failure escape throws `DurableWorkflowAlgorithmFailureException`,
  as on the journal backends. Its previous exception is an `ActivityFailureCauseException` that
  carries the original class and message, not the original exception object. Any other workflow
  failure throws a plain `\RuntimeException` whose message starts with `Workflow "<execution id>"
  failed:`, not the workflow's own exception.
- A workflow that waits on a signal nobody sends fails at once in memory, because nothing else can
  make it progress. On Temporal the run stays open, and `pollForCompletion()` waits until its polls
  run out, then throws `WorkflowStuckException`.

### Prerequisites

- **`ext-grpc`** PHP extension compiled against the `grpc/grpc` package version required by the bridge.
- A running Temporal cluster, **Server 1.20 or newer**. 1.20 is the oldest version Durable
  supports, because it is the first where SQL visibility (PostgreSQL, MySQL, SQLite) accepts custom
  search attributes. On PostgreSQL that takes the `postgres12` persistence plugin
  (`DB=postgres12` with the `auto-setup` image). The older `postgresql` plugin only offers standard
  visibility, which cannot filter on custom search attributes, so the run list's filters fail
  there. CI runs the run-list and visibility-query suites against `temporalio/auto-setup:1.20` on
  PostgreSQL, as well as against a current server.
- The run list's filter by **execution-id prefix** needs **Server 1.23 or newer**: older servers,
  1.22 included, cannot run the `STARTS_WITH` it takes. On those servers the catalog throws a
  `RunFilterUnavailableException` naming 1.23 for a prefix, and the dashboards offer only the name
  filter.
  Filtering by exact workflow name works from 1.20.
- **Updates** (`#[AsUpdateMethod]`, `onUpdate()`) need **Server 1.21 or newer**. On 1.20, when
  the workflow task that answers an update also completes the workflow, the server writes no
  update event to the history, and a later replay does not see the update.
  From 1.21 through 1.24, updates are switched off by default: set the dynamic config value
  `frontend.enableUpdateWorkflowExecution` to `true`. Without it, `WorkflowClient::update()` fails
  with `UpdateWorkflowExecution operation is disabled on this namespace`. In the server's dynamic
  config file (`frontend.enableUpdateWorkflowExecutionAsyncAccepted` is not needed: Durable waits
  for the update's COMPLETED stage):

  ```yaml
  frontend.enableUpdateWorkflowExecution:
    - value: true
  ```

### Install `ext-grpc`

```bash
pecl install grpc
# Add to php.ini: extension=grpc
```

Verify:

```bash
php -m | grep grpc
```

**In a container image, copy the extension instead of compiling it.** `pecl install grpc` takes
about seven minutes, and your image build spends them on every branch. Prebuilt extensions are
published for PHP 8.2 to 8.5, in
thread-safe and non-thread-safe forms; see [gRPC in your container image](../container-images/)
for the `COPY --from` recipes, including php-fpm, mod_php and FrankenPHP.

### Filter runs by workflow name and execution id {#register-durables-search-attributes}

With `search_attributes` turned on, Durable writes two search attributes on every run it starts,
so the run list can filter by workflow name and by execution id. The option is off by default. A
start that sets an attribute not registered in the namespace fails on the server, so register both
**once per namespace, before turning the option on**:

```bash
temporal operator search-attribute create --namespace default \
    --name DurableWorkflowName --type Keyword \
    --name DurableExecutionId --type Keyword
```

- You can run the command again: it succeeds as long as the type is the same.
- **Wait a few seconds before the first start.** The attributes show up in
  `search-attribute list` right away, but for a couple of seconds a start that sets them still fails
  with `Namespace default has no mapping defined for search attribute DurableExecutionId`. A script
  can wait until this query stops failing:

  ```bash
  until temporal workflow list --namespace default --limit 1 \
      --query "DurableExecutionId = 'probe'" >/dev/null 2>&1; do sleep 1; done
  ```

- On **Temporal Cloud**, add the two attributes to the namespace in the Cloud UI or with
  `tcld namespace search-attributes add`.
- With SQL visibility (PostgreSQL, MySQL, SQLite), custom search attributes need Server 1.20 or
  later. A namespace holds at most 10 Keyword attributes there, and Durable uses two of them.

Then turn the option on:

```yaml
# config/packages/durable.yaml
durable:
    temporal:
        search_attributes: true
```

On Laravel, set `'search_attributes' => true` under `temporal` in `config/durable.php`. On Magento,
set `durable/temporal/search_attributes` to `true` in `app/etc/env.php`.

**How the values are written.** A backslash becomes a dot, so a class name reads
`App.Workflow.OrderWorkflow`: Temporal's query parser matches no value that contains a backslash.
A `.` or `%` already in the value becomes `%2E` or `%25`, so two workflow names never end up with
the same value. Temporal documents a limit of 255 characters per value. A longer value keeps at most its
first 189 bytes and ends with a hash of the whole, so an exact filter still finds it, but a prefix
filter only matches within those first bytes.

**Runs started before the option was on don't carry the attributes.** They still show up in the
unfiltered run list, but filtering by workflow name or by execution id doesn't find them.

### Docker Compose setup (local / CI)

The repository includes a ready-to-use `compose.yaml` under `symfony/` that starts:
- **PostgreSQL 16** (shared between the application and Temporal)
- **`temporalio/auto-setup:1.25.2`** (auto-configures schema on startup)
- **Temporal UI** (on port 8088)

```bash
cd symfony
docker compose up -d
```

The `temporal` service registers [Durable's search attributes](#register-durables-search-attributes)
itself, and only reports healthy once a start can use them; the bench turns the option on. Wait for the stack to be healthy, then start the Symfony workers:

```bash
php bin/console messenger:consume durable_workflows --time-limit=3600
php bin/console messenger:consume durable_activities --time-limit=3600
```

The `symfony serve` binary reads `.symfony.local.yaml` and starts workers automatically if configured there.

### Configuration

```yaml
# .env.local (dev/prod)
DURABLE_DSN=temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0
```

```yaml
# config/packages/durable.yaml
durable:
    backend: temporal
    temporal:
        dsn: '%env(DURABLE_DSN)%'
```

Nothing goes in `messenger.yaml` for Temporal. If it still declares `durable_workflows` or
`durable_activities` under this backend, the container fails to compile, and the error names the
transport to remove, because those names belong to the bundle's workers. The exception is
`durable.backend: dbal` with a DSN: workflows then run locally, and those two transports stay the
application's.

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

With an API key, add `api_key=` (URL-encoded); with mTLS, `cert=` and `key=` (paths to PEM files);
with a private CA, `ca=`. See [the DSN parameters](../configuration/#dsn-format) for the whole list.

---

## DBAL backend

The DBAL backend persists the journal, the resume metadata, the parent/child links and the run
catalog (the list of executions a dashboard reads) in a **single SQL database** through Doctrine
DBAL. There is no orchestration server, no sidecar and no `ext-grpc`. See **DUR030**.

### How it works

- The four process-local stores become SQL tables: the event journal, the workflow metadata, the
  parent links between child workflows, and the run catalog. A fifth table,
  `durable_execution_heads`, holds a counter per execution that stops a superseded resume from
  writing to the journal (DUR053). Everything else (replay, command
  buffer, lifecycle) is the code the In-Memory backend already runs.
- Resumes and activities ride **Symfony Messenger**, so use a durable transport (Doctrine, Redis,
  AMQP). An `in-memory://` transport throws away what the SQL journal just persisted.
- Timers ride Messenger `DelayStamp` through `FireWorkflowTimersHandler`.
- Tables are created on **first write**: no migration to run, no `doctrine/migrations` dependency.
  `bin/console durable:setup` creates them up front; run it when `dbal.auto_setup` is `false`, or
  when the first write happens inside a transaction, where auto-creation fails on every platform
  (on MySQL, the `CREATE TABLE` would commit that transaction).

### Configuration

Give the journal a connection of its own. Sharing the application's is strongly discouraged
(DUR054): Durable's transactions then nest inside business ones. A worker that starts with the
journal on the application's default connection logs a warning saying so. Better still, point that
connection at a database (or a schema) and a database user of Durable's own, so that business code
cannot reach the journal's tables at all.

```yaml
# config/packages/doctrine.yaml: the journal on a connection of its own
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            durable:
                url: '%env(resolve:DURABLE_DATABASE_URL)%'
```

```yaml
# config/packages/durable.yaml
durable:
    dbal:
        connection: doctrine.dbal.durable_connection
        lock_factory: lock.factory
    backend: dbal
    activity_transport:
        type: messenger
        transport_name: durable_activities

framework:
    lock:
        default: '%env(LOCK_DSN)%'   # a DBAL URL (postgresql://…, mysql://…), redis://…; not Messenger's doctrine://; shared across workers
```

DoctrineBundle names each connection's service `doctrine.dbal.<name>_connection`. On a database
other than the ORM's, `doctrine:migrations:diff` does not see Durable's tables: they come from the
first write, or from `bin/console durable:setup`.

Adding a `temporal.dsn` keeps the journal in SQL and uses the cluster only to serve Nexus operations.
With `backend: temporal`, the cluster holds the journal instead. The journal lives in exactly one place in
both cases.

### The Doctrine transport on PostgreSQL {#doctrine-transport-on-postgresql}

On PostgreSQL, set `use_notify: false` on Durable's Doctrine transports:

```yaml
framework:
    messenger:
        transports:
            durable_workflows:
                dsn: 'doctrine://default?queue_name=durable_workflows'
                options: { use_notify: false }
            durable_activities:
                dsn: 'doctrine://default?queue_name=durable_activities'
                options: { use_notify: false }
```

Once a queue is empty, Messenger's PostgreSQL transport reads it again only on a notification or
after 60 seconds (`check_delayed_interval`). A worker that consumes both queues over one connection
can miss that notification, and the resume an activity sends then waits up to 60 seconds, or until
the next `durable:worker` starts, whatever the `--sleep` value. With `use_notify: false`, the transport polls
each queue on every loop, as it does on MySQL.

### One resume at a time per execution {#one-resume-at-a-time--the-thing-to-get-right}

Temporal serialises workflow tasks for one execution server-side. There is no server here, so two
consumers can dequeue two resumes of the same execution and replay the same fiber in parallel,
each appending its own commands. The result is **duplicated activities and a forked journal**.

Durable prevents this with a per-execution lock (`SingleResumeLockMiddleware`), registered
automatically when the DBAL event store is active. **The lock is only as safe as your lock store.**
With several workers, an in-memory or per-process `lock.factory` lets through exactly the failure
the lock exists to prevent. Configure a shared one.

### When to use it

- **Production without operating a cluster.** A Symfony app that already has a database and a
  Messenger transport.
- Long-running workflows that must survive deploys and restarts, at a scale one database can hold.

It does not offer search-attribute queries, cron schedules, or the throughput and visibility of a
Temporal cluster. The capability matrix below lists the differences.

---

## Illuminate backend

The same four stores exist on `Illuminate\Database\Connection`, as
[`gplanchat/durable-bridge-illuminate`](../packages/#gplanchatdurable-bridge-illuminate--the-laravel-backend)
with the same journal and the same trade against Temporal.

**Its trade against Temporal is the DBAL backend's, word for word.** Only the connection differs:
`Illuminate\Database\Connection` instead of Doctrine's. Give it a connection of its own, separate
from the application's (DUR054). See [DUR047](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR047-laravel-the-host-that-measured-before-it-wired.md).

### Binding it through `gplanchat/durable-laravel` {#what-binds-it-is-not-this-pages-yaml}

Illuminate is **not a fourth value of `backend`**, and never will be, because a Laravel
application does not read this page's YAML. The bridge is the storage half. **`gplanchat/durable-laravel`
binds it**, through its own published `config/durable.php`.

That package carries the queue side too: activities and resumes as jobs, a timer as a deferred
timer-firing job on the queue's own delay, and the per-execution exclusion the DBAL section
describes. Its own [Packages entry](../packages/#gplanchatdurable-laravel--the-laravel-integration) has the
configuration, the three settings the package does not accept, and the two behaviours that look
like bugs and are not.

---

## Choosing a backend per environment

| Environment | Backend |
|---|---|
| Unit tests | In-Memory (`DurableTestCase`) |
| Integration tests | In-Memory (`DurableBundleTestTrait` + `KernelTestCase`) |
| CI with Temporal | Temporal (`temporal-integration` group) |
| Local dev | Any: In-Memory for speed, a journal backend for realism |
| Production, no cluster | DBAL on Symfony, Illuminate on Laravel |
| Production, at scale | Temporal |

---

## Capability matrix

All four backends run the **same fiber driver** and the same activity execution path. What differs
is what the surrounding platform can offer. The two SQL columns differ only in the connection they
sit on, so their answers match on every row except the transport and what the host delivers
(signals, updates and Nexus serving).

The Magento Database column is not a backend of the current release. Its code is in
development (epic [#740](https://github.com/gplanchat/durable-dev/issues/740)), and none of it is
on `main`. A cell reads "not yet" until the capability merges.

| Capability | In-Memory | DBAL | Illuminate | Temporal | Magento Database |
|---|---|---|---|---|---|
| Activities, retries, timeouts | ✅ | ✅ | ✅ | ✅ | not yet |
| Timers, side effects | ✅ | ✅ (Messenger delays) | ✅ (queue delays) | ✅ | not yet |
| Signal, update and query handlers in a workflow | ✅ | ✅ | ✅ | ✅ | not yet |
| Sending a signal or an update from the application | ✅ (Symfony message) | ✅ (Symfony message) | ❌ (Laravel delivers none) | ✅ (client or Symfony message) | not yet |
| The update's result returned to the caller | ❌ | ❌ | ❌ | ✅ (`WorkflowClient::update()`) | not yet |
| Reading a query from the application | ❌ | ❌ | ❌ | ✅ (`WorkflowClient::query()`) | not yet |
| Child workflows | ✅ | ✅ | ✅ | ✅ | not yet |
| `ParentClosePolicy` cascade | ✅ | ✅ | ✅ | ✅ (server-driven) | not yet |
| Continue-as-new | ✅ | ✅ | ✅ | ✅ | not yet |
| Cancellation with compensation (a parent's `RequestCancel`) | ✅ | ✅ | ✅ | ✅ | not yet |
| Cancellation requested from outside | ❌ | ❌ | ❌ | ✅ | not yet |
| Survives process restart | ❌ | ✅ | ✅ | ✅ | not yet |
| Task serialisation per execution | n/a (single process) | application lock | application lock | ✅ server-side | not yet |
| Search attributes | journaled only | journaled only | journaled only | ✅ indexed and queryable | not yet |
| Cron schedules | ❌ no scheduler | ❌ no scheduler | ❌ no scheduler | ✅ | not yet |
| History retention / visibility API | ❌ | your SQL table | your SQL table | ✅ | not yet |
| Calling a Nexus operation | ❌ | ❌ | ❌ | ✅ | not yet |
| Serving a Nexus operation | ✅ with `temporal.dsn` (Symfony) | ✅ with `temporal.dsn` (Symfony) | ❌ | ✅ (Symfony, Laravel, Magento) | not yet |

`gplanchat/durable-magento` ships no signal or update delivery. On the journal backends, a signal
or an update sent from the application is journaled, and the workflow's next pass handles it; the
sender gets no answer. A query has no application-side entry point there at all.

No backend but Temporal has a scheduler or a cross-namespace boundary, so cron and Nexus have no
equivalent on the other three. Nexus fails explicitly, with one gap on Laravel, described below.
A child workflow's `namespace`, `taskQueue` and `cronSchedule` fail explicitly too: a journal
backend fails with `UnsupportedByBackendException` naming the option. Search attributes are the
exception: a child workflow's are written into the journal and nothing reads them outside Temporal,
and the start options of a root workflow exist only on the Temporal client. A Nexus *call* fails at
the call. A Nexus *handler* with no route never sees a failing call: it is a service that never
receives anything. On Symfony, the container build fails when
`durable.temporal.dsn` is not set. On Magento, `bin/magento durable:worker --role=nexus` fails with
`A Nexus worker needs a cluster` when `app/etc/env.php` has no DSN.
On Laravel, nothing fails at boot. Outside `temporal`, nothing resolves the Nexus registry: a
handler listed in `durable.nexus.handlers` raises nothing and receives nothing, and
`php artisan durable:nexus-worker` ends with `Command "durable:nexus-worker" is not defined.`, which
does not name the backend (see [#931](https://github.com/gplanchat/durable-dev/issues/931)).

### Starting a run from a Magento observer {#magento-start-blocks}

`RuntimeFactory::resumeDispatcher()->dispatchNewWorkflowRun()` has the same signature and the same
failure behaviour on the memory and Temporal backends of Magento: a workflow that fails does not throw from the call, and
an undeclared workflow does. One difference remains, and it is named here as an exception to the
rule that the application behaves the same on every backend. On Temporal, the call starts the run
and returns. On the Magento memory backend, the run executes in the calling process, so the request
waits for it, for up to `budgetSeconds` (10 by default), and a workflow that waits on a signal or a
long timer holds the request for the whole budget. Nothing else can advance an in-memory run, so
the wait cannot be removed. Set `durable/temporal/dsn` where a request must not wait.

A second difference concerns a failure that the call swallows. The in-memory journal ends with the
request, so the log line is the only trace of it, and only when a logger is configured. On Temporal,
the failure also stays in the cluster history.

---

## Retry limits differ on one setting {#retry-semantics-are-identical}

An activity with no attempt bound retries **indefinitely** on every backend, which is the Temporal default.

`max_activity_retries` is the exception. On the in-memory and journal backends (DBAL, Illuminate),
the worker narrows the activity's own `RetryLimit` to that ceiling, and the stricter of the two
applies; at `0` it caps nothing. On Temporal, the cluster retries from the activity's own
`RetryLimit` and the setting is not read: an activity that the ceiling stops on the other backends
keeps retrying on Temporal.

See [Failures and retries](../failures/) and [Options](../options/#retrylimit).

---

## Writing your own backend

Two ports define a backend: `WorkflowCommandBufferInterface` for what a workflow asks for, and
`WorkflowHistorySourceInterface` for what already happened.

Both carry **value objects**, not primitives. An implementation receives the options as the caller
built them (retry limits, timeouts, task queues, cron schedules) and owns the translation to its
own representation, including serialisation and any reading of a clock.

`startTimer()` receives a **delay**, not a deadline. You turn it into an instant, with your own
clock. This lets a test harness advance a virtual clock, and lets the Temporal driver pass the
duration the server expects.

The contributor decision record is [DUR031](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR031-value-objects-across-ports-and-wire-ownership.md).

---

## See also

- [Configuration reference](../configuration/) lists every `durable.yaml` key.
- [Getting started](../getting-started/) covers Messenger routing and worker commands.
- [Testing workflows](../testing/) shows the In-Memory backend used in tests.
