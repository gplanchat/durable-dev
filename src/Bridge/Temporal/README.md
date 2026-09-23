# `gplanchat/durable-bridge-temporal` (`src/Bridge/Temporal`)

**gRPC** bridge (without the official Temporal PHP SDK) to persist the Durable journal in a **minimal Temporal workflow**.

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

**Deployment invariant**: one Temporal server, one DSN, declared once in `durable.temporal.dsn`. The Durable bundle builds every worker from it — see [Workers](#workers).

## Requirements

- PHP **ext-grpc**
- A reachable Temporal frontend (e.g. `host:7233`)

## Components

| Class | Role |
|--------|------|
| `TemporalJournalEventStore` | Implements `Gplanchat\Durable\Store\EventStoreInterface` |
| `TemporalJournalTransport` | Symfony Messenger **receive-only** receiver: each `get()` long-polls a workflow task, replays the execution from the server's history and answers the task. The bundle registers it as `durable_workflows` |
| `TemporalActivityWorkerTransport` | **Receive-only** receiver: each `get()` long-polls an activity task, runs the handler and reports the outcome. The bundle registers it as `durable_activities` |
| `TemporalNexusWorkerTransport` | **Receive-only** receiver: each `get()` long-polls a Nexus task and serves the operation the application declared. The bundle registers it as `durable_nexus` once a handler exists |
| `TemporalBridgeBundle` | Deprecated, registers nothing: remove it from `config/bundles.php` |

## Connection DSN

```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&tls=0
```

It goes once, in `durable.temporal.dsn`. Query parameters: `namespace`, `task_queue` or `journal_task_queue`, `workflow_type`, `workflow_task_queue`, `activity_task_queue`, `nexus_task_queue`, `identity`, `tls` (bool). Unknown keys are ignored today (a typo falls back to the default silently — issue #353 makes them fail).

## Workers

One server, one DSN: the Durable bundle builds the workers from `durable.temporal.dsn`, and `messenger:consume` finds them by name. `messenger.yaml` declares no Temporal transport; if it declares one under a worker's name, the container refuses to compile and names it.

| Worker | Polls | Exists when |
|---|---|---|
| `durable_workflows` | workflow tasks | `durable.temporal.dsn` is set and `durable.temporal.journal` is not `false` |
| `durable_activities` | activity tasks | same |
| `durable_nexus` | Nexus tasks | `durable.temporal.dsn` is set and at least one Nexus handler is declared |

```bash
bin/console messenger:consume durable_workflows durable_activities durable_nexus
```

The workers do their work inside `get()` and hand nothing to the Messenger worker, so `retry_strategy`, `failure_transport` and `messenger:consume --limit` have no effect on them: retries are the server's retry policy, and a failed poll is retried by the next `get()`.

## Symfony

1. In the monorepo the code lives under `src/Bridge/Temporal`; in a split repo: `composer require gplanchat/durable-bridge-temporal`.
2. Set `durable.temporal.dsn` in the Durable bundle configuration.
3. `messenger:consume durable_workflows durable_activities`, plus `durable_nexus` if the application serves operations — see [Workers](#workers).

## FrankenPHP worker

Same idea as `messenger:consume`: run the Messenger worker under FrankenPHP worker mode (or systemd) with `messenger:consume durable_workflows durable_activities`.

## License

**MIT** — see [`LICENSE`](LICENSE) in this directory and [WA004](https://github.com/gplanchat/durable-dev/blob/main/documentation/wa/WA004-mit-license-distribution.md).

## Further reading

- **DUR019** — Temporal gRPC bridge and journal: [`documentation/adr/DUR019-temporal-grpc-bridge-and-journal.md`](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR019-temporal-grpc-bridge-and-journal.md)
