# `gplanchat/durable-bridge-temporal` (`src/Bridge/Temporal`)

**gRPC** bridge (without the official Temporal PHP SDK) to persist the Durable journal in a **minimal Temporal workflow**. The wire is `ext-grpc` by default; every collaborator depends on `WorkflowServiceClientInterface`, so the sibling `durable-bridge-temporal-http` package can carry the same RPCs over curl.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **The tests are in the monorepo, not here.** This split carries source only. What covers it is
> `tests/unit/Bridge/Temporal/` in the monorepo, run by its `unit` suite.
>
> **Documentation**: [durable.rocks](https://durable.rocks).

PHP namespace: **`Gplanchat\Bridge\Temporal`**.

**Deployment invariant**: when Temporal is enabled for Durable, the **journal** (`EventStore`) and **application queues** share the **same** Temporal connection (`temporal://…`). What a transport does is selected by its **`purpose`** — `journal`, `application`, `activity_worker` or `nexus_worker`, see [Transport purposes](#transport-purposes) — given as `options.purpose` or `?purpose=` in the DSN, or inferred: an **`inner`** DSN means `application`, nothing means `journal`. Schemes **`temporal-journal://`** and **`temporal-application://`** are still accepted and normalized to **`temporal://`**.

## Requirements

- PHP **ext-grpc** for the default transport, or the `gplanchat/durable-bridge-temporal-http` package for `transport=grpc-curl` (curl over HTTP/2, no extension) and `transport=http` (the server JSON gateway, client calls only)
- A reachable Temporal frontend (e.g. `host:7233`)

## Components

| Class | Role |
|--------|------|
| `TemporalJournalEventStore` | Implements `Gplanchat\Durable\Store\EventStoreInterface` |
| `TemporalTransportFactory` | Single **`temporal://`** factory: journal (`TemporalJournalTransport`, receive-only) or application (`TemporalApplicationTransport` + `inner`) from `purpose` / `inner` |
| `TemporalJournalTransport` | Symfony Messenger **receive-only** transport (same `temporal://…` DSN, no `inner` by default); consumed with `messenger:consume <transport_name>` |
| `TemporalApplicationTransport` | Wraps a real Messenger transport (`temporal://…?inner=…` or `options.inner`) for Durable application messages |
| `TemporalActivityWorkerTransport` | Symfony Messenger **receive-only** transport (`purpose: activity_worker`): each `get()` long-polls an activity task, runs the handler and reports the outcome |
| `TemporalNexusWorkerTransport` | Symfony Messenger **receive-only** transport (`purpose: nexus_worker`): each `get()` long-polls a Nexus task and serves the operation the application declared |
| `TemporalBridgeBundle` | Registers the `temporal://` Messenger factory |

## Transport DSN (single scheme)

```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&tls=0
```

Query parameters: `namespace`, `task_queue` or `journal_task_queue`, `workflow_type`, `workflow_task_queue`, `activity_task_queue`, `nexus_task_queue`, `identity`, `tls` (bool), `inner`, `purpose`, `transport` (`grpc`, `grpc-curl`, `http`). Unknown keys are ignored today (a typo falls back to the default silently — issue #353 makes them fail).

## Transport purposes

One factory, four transports. The `purpose` decides which one a `temporal://` DSN builds; the last column is the setup error the factory throws when the wiring it needs is missing, and what to do about it.

| `purpose` | What the transport does | Consumed by | When you see this |
|---|---|---|---|
| `journal` — the default when there is no `inner` | **Receive-only.** Each `get()` long-polls a workflow task on the journal task queue, replays the execution from the server's history and answers the task. | `messenger:consume <name>` | *Temporal journal transport requires a WorkflowRegistry (enable durable.temporal.dsn in the Durable bundle)* — the bundle did not wire the registry into the factory: set `durable.temporal.dsn`, or build the transport with `TemporalJournalTransport::fromConnection()` yourself. |
| `application` — inferred from `inner` | Wraps a real Messenger transport (`inner`) so Durable's application messages ride it; the Temporal connection is shared, the traffic goes through `inner`. | whoever consumes the inner transport | *Temporal application transport requires inner= in the temporal:// DSN or options.inner* — add the inner DSN. |
| `activity_worker` | **Receive-only.** Each `get()` long-polls an activity task on the activity task queue, runs the activity handler and reports completion or failure to the server. | `messenger:consume <name>` | *purpose=activity_worker requires TemporalActivityWorker (inject it via the Durable bundle DI or wire TemporalActivityWorkerTransport manually)* — the bundle wires the worker when `durable.temporal.dsn` is set; outside the bundle, construct the transport with a `TemporalActivityWorker`. |
| `nexus_worker` | **Receive-only.** Each `get()` long-polls the Nexus task queue and serves the operations the application declares (`#[AsNexusServiceHandler]`, `#[FulfilsNexusOperation]`). | `messenger:consume <name>` | *purpose=nexus_worker requires TemporalNexusWorker (declare at least one durable.nexus_handler, or wire TemporalNexusWorkerTransport manually)* — declare a handler, or drop the transport: a shop that only calls operations does not serve any. |

Any other value fails with *Unknown temporal purpose "…", expected journal, application, activity_worker, or nexus_worker*.

The three receive-only transports do their work inside `get()` and hand nothing to the Messenger worker, so `retry_strategy`, `failure_transport` and `messenger:consume --limit` have no effect on them: retries are the server's retry policy, and a failed poll is retried by the next `get()`. A worker deployment typically consumes the three at once:

```yaml
framework:
    messenger:
        transports:
            durable_temporal_journal:
                dsn: '%env(DURABLE_DSN)%'
            durable_temporal_activity:
                dsn: '%env(DURABLE_DSN)%'
                options: { purpose: activity_worker }
            durable_temporal_nexus:
                dsn: '%env(DURABLE_DSN)%'
                options: { purpose: nexus_worker }
```

```bash
bin/console messenger:consume durable_temporal_journal durable_temporal_activity durable_temporal_nexus
```

### Journal (receive-only)

Without **`inner`** and without `options.purpose=application`, the transport is **`TemporalJournalTransport`**.

### Application queues (`inner`)

```
temporal://127.0.0.1:7233?namespace=default&inner=in-memory://&workflow_task_queue=durable-workflows&activity_task_queue=durable-activities&tls=0
```

Or `temporal://…` without `inner` in the URL and **`options: { purpose: application, inner: 'in-memory://' }`** in Messenger config.

- **`inner`** (required for application mode): DSN of the real Symfony Messenger transport (Redis, Doctrine, in-memory, etc.).
- **`workflow_task_queue`** / **`activity_task_queue`**: used for gRPC evolution; while the envelope delegates to **`inner`**, application traffic uses that inner transport.

## Symfony

1. In the monorepo the code lives under `src/Bridge/Temporal`; in a split repo: `composer require gplanchat/durable-bridge-temporal`.
2. Register `Gplanchat\Bridge\Temporal\TemporalBridgeBundle` in the kernel.
3. `framework.messenger.transports.<name>: 'temporal://…'` (without `inner`, journal DSN — e.g. `journal_task_queue=durable-journal`).
4. `messenger:consume <name>` (standard Symfony worker; poll and journal task handling are inside `TemporalJournalTransport::get()`). Add an `activity_worker` transport for the activities and, if the application serves operations, a `nexus_worker` one — see [Transport purposes](#transport-purposes).
5. Wire `EventStoreInterface` to `TemporalJournalEventStore` where appropriate (explicit DI).

## FrankenPHP worker

Same idea as `messenger:consume`: run the Messenger worker under FrankenPHP worker mode (or systemd) with `messenger:consume <journal_transport>` pointing at `temporal://…` without `inner`.

## License

**MIT** — see [`LICENSE`](LICENSE) in this directory and [WA004](https://github.com/gplanchat/durable-dev/blob/main/documentation/wa/WA004-mit-license-distribution.md).

## Further reading

- **DUR019** — Temporal gRPC bridge and journal: [`documentation/adr/DUR019-temporal-grpc-bridge-and-journal.md`](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR019-temporal-grpc-bridge-and-journal.md)
