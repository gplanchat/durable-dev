# `gplanchat/durable-bridge-temporal` (`src/Bridge/Temporal`)

**gRPC** bridge (without the official Temporal PHP SDK) to persist the Durable journal in a **minimal Temporal workflow**.

PHP namespace: **`Gplanchat\Bridge\Temporal`**.

**Deployment invariant**: when Temporal is enabled for Durable, the **journal** (`EventStore`) and **application queues** share the **same** Temporal connection (`temporal://…`). **Access mode** (journal receive-only vs application envelope) is selected via **`options.purpose`** (`journal` \| `application`) or inferred (presence of **`inner`** ⇒ application). Schemes **`temporal-journal://`** and **`temporal-application://`** are still accepted and normalized to **`temporal://`**.

## Requirements

- PHP **ext-grpc**
- A reachable Temporal frontend (e.g. `host:7233`)

## Components

| Class | Role |
|--------|------|
| `TemporalJournalEventStore` | Implements `Gplanchat\Durable\Store\EventStoreInterface` |
| `TemporalTransportFactory` | Single **`temporal://`** factory: journal (`TemporalJournalTransport`, receive-only) or application (`TemporalApplicationTransport` + `inner`) from `purpose` / `inner` |
| `TemporalJournalTransport` | Symfony Messenger **receive-only** transport (same `temporal://…` DSN, no `inner` by default); consumed with `messenger:consume <transport_name>` |
| `TemporalApplicationTransport` | Wraps a real Messenger transport (`temporal://…?inner=…` or `options.inner`) for Durable application messages |
| `TemporalBridgeBundle` | Registers the `temporal://` Messenger factory |

## Transport DSN (single scheme)

```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&tls=0
```

Query parameters: `namespace`, `task_queue` or `journal_task_queue`, `workflow_type`, `workflow_task_queue`, `activity_task_queue`, `identity`, `tls` (bool).

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
4. `messenger:consume <name>` (standard Symfony worker; poll and journal task handling are inside `TemporalJournalTransport::get()`).
5. Wire `EventStoreInterface` to `TemporalJournalEventStore` where appropriate (explicit DI).

## FrankenPHP worker

Same idea as `messenger:consume`: run the Messenger worker under FrankenPHP worker mode (or systemd) with `messenger:consume <journal_transport>` pointing at `temporal://…` without `inner`.

## Further reading

- **DUR019** — Temporal gRPC bridge and journal: [`documentation/adr/DUR019-temporal-grpc-bridge-and-journal.md`](../../../documentation/adr/DUR019-temporal-grpc-bridge-and-journal.md)
