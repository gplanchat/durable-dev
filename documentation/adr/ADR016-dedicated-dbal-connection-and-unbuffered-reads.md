# Dedicated DBAL connection and unbuffered reads

ADR016-dedicated-dbal-connection-and-unbuffered-reads
===

Status: **Accepted**

## Context

The Durable bundle persists workflow history and related data through **Doctrine DBAL** (`DbalEventStore`, `DbalWorkflowMetadataStore`, `DbalChildWorkflowParentLinkStore`, optional `DbalActivityTransport`).

Two operational needs often arise:

1. **Isolation** — Use a **separate `Connection` instance** from the rest of the application so long-lived or cursor-style reads on the event stream do not share PDO state with unrelated queries (transactions, buffered result sets, locks).
2. **Memory** — For **very large** histories on **MySQL**, the default PHP MySQL driver may **buffer the full result set** on the client even when application code iterates row-by-row. Disabling buffered queries (**unbuffered** mode) avoids loading the entire history into client memory at once.

**Primary mitigation for large streams** — `DbalEventStore` reads the event stream using **keyset pagination** (`WHERE execution_id = ? AND id > ? … LIMIT ?`) so each SQL result set is **bounded**; see [ADR019](ADR019-event-store-cursor-pagination.md). Unbuffered mode remains an **optional** extra for driver-specific edge cases, not the default answer to client-side buffering.

`DbalEventStore::readStream()` yields via `Result::iterateAssociative()` **per page**. Unbuffered mode addresses **driver-level** buffering when a single result set is still too large for the client.

## Decision

1. **Bundle configuration** — Expose **`durable.dbal_connection`**: the **Doctrine connection name** used for all Durable DBAL adapters (default: `default`).
2. **Service alias** — Register **`durable.dbal.connection`** as an alias to `doctrine.dbal.{name}_connection` so application code (e.g. `durable:schema:init`) injects the same connection as the bundle.
3. **Documentation** — Describe how to declare a second connection in `doctrine.yaml`, optionally point it at a dedicated database URL, and how to set **PDO MySQL** options for unbuffered reads.

Unbuffered configuration is **driver-specific** and belongs in **Doctrine DBAL** connection options, not inside `DbalEventStore`.

## Configuration (Symfony)

### Dedicated connection

Declare a named connection (e.g. `durable`) under `doctrine.dbal.connections`:

```yaml
# config/packages/doctrine.yaml
parameters:
    env(DATABASE_URL): '%env(resolve:DATABASE_URL)%'
    env(DURABLE_DATABASE_URL): '%env(resolve:DATABASE_URL)%'  # or a separate DSN for full DB isolation

doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_URL)%'
            durable:
                url: '%env(resolve:DURABLE_DATABASE_URL)%'
```

Point the bundle at that name:

```yaml
# config/packages/durable.yaml
durable:
    dbal_connection: durable
```

Effects:

- **Same DSN for `default` and `durable`** — Two **separate** `Connection` / PDO instances; no shared cursor or transaction state.
- **Different DSN** — Physical isolation of durable tables from the main application database.

The sample app in this repository uses **`dbal_connection: durable`** with two connections sharing the same SQLite file by default (`DURABLE_DATABASE_URL` mirrors `DATABASE_URL`).

### Schema command

`php bin/console durable:schema:init` uses **`durable.dbal.connection`** so tables are created on the connection selected by `durable.dbal_connection`.

### Messenger transports

Messenger transports using `doctrine://...` are **independent** of `durable.dbal_connection`. If you want workflow/activity queues on the same physical pool as Durable stores, align the DSN (e.g. `doctrine://durable?...`); otherwise they remain on `default` by design.

## Unbuffered reads (MySQL / PDO)

For **pdo_mysql**, set:

```php
\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false
```

Expose it on the **`durable`** connection only. With **DoctrineBundle**, PDO attributes are usually mapped under **`options`** (passed to the underlying PDO). Example:

```yaml
doctrine:
    dbal:
        connections:
            durable:
                url: '%env(resolve:DURABLE_DATABASE_URL)%'
                driver: pdo_mysql
                options:
                    1000: false   # PDO::MYSQL_ATTR_USE_BUFFERED_QUERY (pdo_mysql / mysqlnd)
```

Alternative with Symfony’s constant tag (if your YAML loader supports it):

```yaml
                options:
                    !php/const PDO::MYSQL_ATTR_USE_BUFFERED_QUERY: false
```

If you rely on **`DATABASE_URL`** alone and cannot attach `options`, use a **DBAL middleware** or **wrapper** that sets the PDO attribute after connect — scoped to the **`durable`** connection only.

### Constraints

- While an **unbuffered** result is open on a connection, **do not** run another statement on that **same** connection until the iterator is fully consumed (or the result closed). Durable uses a **dedicated** connection partly to avoid mixing with other traffic.
- **SQLite** and **PostgreSQL** behave differently; unbuffered mode above is **MySQL-specific**.
- Connection poolers or proxies may still buffer; validate in your environment.

## Consequences

- Operators can **isolate** Durable persistence and tune **MySQL** client streaming without changing domain code.
- **Positive**: Clear separation of concerns; optional `DURABLE_DATABASE_URL` for security/ops.
- **Negative**: More configuration to maintain; unbuffered MySQL requires discipline on the same connection.

## References

- `Gplanchat\Durable\Bundle\DependencyInjection\Configuration` — `dbal_connection`
- `symfony/config/packages/durable.yaml` — sample `dbal_connection: durable`
- `symfony/config/packages/doctrine.yaml` — sample `connections.durable`
