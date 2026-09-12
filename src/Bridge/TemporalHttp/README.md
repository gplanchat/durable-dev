# `gplanchat/durable-bridge-temporal-http` (`src/Bridge/TemporalHttp`)

Temporal **without `ext-grpc`**: two `WorkflowServiceClientInterface` implementations for the
Temporal bridge of [gplanchat/durable](https://github.com/gplanchat/durable-dev). The curl one
is picked automatically by `temporal://` when the extension is not loaded; the JSON one is what
`temporal+http://` and `temporal+https://` select.

> **Read-only mirror.** This repository is a subtree-split of
> **[gplanchat/durable-dev](https://github.com/gplanchat/durable-dev)**, published so Composer can
> require this package on its own. Issues and pull requests are disabled here — open them **[on the
> monorepo](https://github.com/gplanchat/durable-dev/issues)**.
>
> **The tests are in the monorepo, not here.** `tests/unit/Bridge/TemporalHttp/` and
> `tests/integration/TemporalHttp/` cover it.

PHP namespace: **`Gplanchat\Bridge\TemporalHttp`**.

## Requirements

- PHP `ext-curl` built with HTTP/2 (nghttp2), which every mainstream distribution ships
- `gplanchat/durable-bridge-temporal`, whose protobuf stubs and `WorkflowServiceClientInterface` this package implements
- `google/protobuf`, the pure-PHP runtime; the `protobuf` C extension is optional and only faster

## Transports

| DSN | Class | Port | Covers |
|---|---|---|---|
| `temporal://`, `temporal+tls://` without `ext-grpc` (or `transport=grpc-curl` to force it) | `CurlGrpcWorkflowServiceClient` | 7233 (the gRPC frontend) | Every RPC the bridge uses, **workers included**. Same protocol as `ext-grpc`: one HTTP/2 POST per unary call, gRPC frame in the body, status in the trailers. |
| `temporal+http://`, `temporal+https://` | `JsonGatewayWorkflowServiceClient` | 7243 (the JSON gateway) | The client side: start, signal, query, update, describe, list, history, cancel, terminate, and the activity completion RPCs. **No task queue poll and no workflow or Nexus task response**: the server does not bind them over HTTP. Calling one throws with gRPC code 12 (`UNIMPLEMENTED`). |

```
temporal://127.0.0.1:7233?namespace=default           # gRPC: ext-grpc if loaded, else curl (logged once)
temporal+http://127.0.0.1?namespace=default           # JSON gateway, port defaults to 7243
temporal://127.0.0.1:7233?namespace=default&transport=grpc-curl   # curl even with the extension
```

Both throw the same `\RuntimeException` as the `ext-grpc` path, with the gRPC status code as the
exception code, so nothing above the transport tells them apart. Which one a process ended up with
is printed by `durable:execution:diagnose` (Symfony) and by the worker commands (Laravel) at start.

## Enabling the JSON gateway on a self-hosted server

```yaml
services:
  frontend:
    rpc:
      httpPort: 7243
```

The development server takes `--http-port 7243`. Temporal Cloud exposes the gateway on its own
endpoint; consult its documentation for the address.

## Limits

- A proxy or load balancer that only speaks HTTP/1.1 breaks `grpc-curl`, as it breaks `ext-grpc`;
  `http` survives it.
- Protobuf encoding is the pure-PHP runtime: correct, and slower than the C extension on large
  history pages. Install `ext-protobuf` if that shows up in a profile.
