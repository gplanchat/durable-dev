# `gplanchat/durable-bridge-temporal` (`src/Bridge/Temporal`)

**gRPC** bridge (without the official Temporal PHP SDK) to persist the Durable journal in a **minimal Temporal workflow**. The wire is `ext-grpc` by default; every collaborator depends on `WorkflowServiceClientInterface`, so the same RPCs also travel over curl, without the extension.

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

- PHP **ext-grpc**, or **ext-curl** with HTTP/2 (used automatically when `ext-grpc` is not loaded; also the `temporal+http://` JSON gateway, client calls only) — see [Without `ext-grpc`](#without-ext-grpc-curl-or-the-json-gateway)
- A reachable Temporal frontend (e.g. `host:7233`)

## Components

| Class | Role |
|--------|------|
| `TemporalJournalEventStore` | Implements `Gplanchat\Durable\Store\EventStoreInterface` |
| `TemporalJournalTransport` | Symfony Messenger **receive-only** receiver: each `get()` long-polls a workflow task, replays the execution from the server's history and answers the task. The bundle registers it as `durable_workflows` |
| `TemporalActivityWorkerTransport` | **Receive-only** receiver: each `get()` long-polls an activity task, runs the handler and reports the outcome. The bundle registers it as `durable_activities` |
| `TemporalNexusWorkerTransport` | **Receive-only** receiver: each `get()` long-polls a Nexus task and serves the operation the application declared. The bundle registers it as `durable_nexus` once a handler exists |
| `GrpcWorkflowServiceClient` | The WorkflowService over gRPC; how each call travels is a `GrpcTransport`'s |
| `GrpcTransport` | One gRPC unary call, for any service: `ExtGrpcTransport` (`ext-grpc`), `Http\CurlGrpcTransport` (`ext-curl`, HTTP/2). `WorkflowServiceClientFactory` picks one from `transport=` |
| `TemporalBridgeBundle` | Deprecated, registers nothing: remove it from `config/bundles.php` |

## Connection DSN

```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal
```

It goes once, in `durable.temporal.dsn`. Schemes: `temporal://` (gRPC), `temporal+tls://` (gRPC over TLS), `temporal+http://` and `temporal+https://` (the server JSON gateway, port 7243 by default). Query parameters: `namespace`, `task_queue` or `journal_task_queue`, `workflow_type`, `workflow_task_queue`, `activity_task_queue`, `nexus_task_queue`, `identity`, `tls` (bool, the older spelling of `+tls`), `transport` (`auto` by default: ext-grpc when loaded, curl otherwise; `grpc`, `grpc-curl`, `http` to force one). Unknown keys are ignored today (a typo falls back to the default silently — issue #353 makes them fail).

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

## Without `ext-grpc`: curl, or the JSON gateway

The classes live under `Gplanchat\Bridge\Temporal\Http` and need `ext-curl` built with HTTP/2
(nghttp2), which every mainstream distribution ships.

| DSN | Class | Port | Covers |
|---|---|---|---|
| `temporal://`, `temporal+tls://` without `ext-grpc` (or `transport=grpc-curl` to force it) | `GrpcWorkflowServiceClient` over `CurlGrpcTransport` | 7233 (the gRPC frontend) | Every RPC the bridge uses, **workers included**. Same protocol as `ext-grpc`: one HTTP/2 POST per unary call, gRPC frame in the body, status in the trailers. |
| `temporal+http://`, `temporal+https://` | `JsonGatewayWorkflowServiceClient` | 7243 (the JSON gateway) | The client side: start, signal, query, update, describe, list, history, cancel, terminate, and the activity completion RPCs. **No task queue poll and no workflow or Nexus task response**: the server does not bind them over HTTP. Calling one throws with gRPC code 12 (`UNIMPLEMENTED`). |

```
temporal://127.0.0.1:7233?namespace=default           # gRPC: ext-grpc if loaded, else curl (logged once)
temporal+http://127.0.0.1?namespace=default           # JSON gateway, port defaults to 7243
temporal://127.0.0.1:7233?namespace=default&transport=grpc-curl   # curl even with the extension
```

Both throw the same `\RuntimeException` as the `ext-grpc` path, with the gRPC status code as the
exception code, so nothing above the transport tells them apart. Which one a process ended up with
is printed by `durable:execution:diagnose` (Symfony) and by the worker commands (Laravel) at start.

### Enabling the JSON gateway on a self-hosted server

```yaml
services:
  frontend:
    rpc:
      httpPort: 7243
```

The development server takes `--http-port 7243`. Temporal Cloud exposes the gateway on its own
endpoint; consult its documentation for the address.

### Limits

- A proxy or load balancer that only speaks HTTP/1.1 breaks `grpc-curl`, as it breaks `ext-grpc`;
  `http` survives it.
- Protobuf encoding is the pure-PHP runtime: correct, and slower than the C extension on large
  history pages. Install `ext-protobuf` if that shows up in a profile.

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
