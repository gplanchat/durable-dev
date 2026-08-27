# gplanchat/durable-bridge-illuminate

Durable execution on the database connection **Laravel already owns**.

Four stores behind the four storage ports, written against `Illuminate\Database\Connection` — the
query builder, not Eloquent. No Doctrine anywhere in the tree.

| Port | This package |
|---|---|
| `EventStoreInterface` | `IlluminateEventStore` |
| `WorkflowMetadataStore` | `IlluminateWorkflowMetadataStore` |
| `ChildWorkflowParentLinkStoreInterface` | `IlluminateChildWorkflowParentLinkStore` |
| `WorkflowRunCatalogInterface` (+ `WorkflowRunProjectionInterface`) | `IlluminateWorkflowRunCatalog` |

## Why the connection matters more than the idiom

**DUR030** sells durable execution on one database with no cluster, and that only pays if the
journal append and the business write land in **one transaction**. Otherwise the activity writes,
the process dies before the journal records that it did, and replay runs it a second time — the
exact failure durable execution exists to prevent.

A store on `DB::connection()` is inside `DB::transaction()` by construction. Handing Doctrine DBAL
the PDO out of `DB::connection()->getPdo()` reaches the same guarantee and is a workaround; this is
the plain answer.

## Why the query builder and not Eloquent

Journal rows are append-only facts: `execution_id`, `event_type`, a JSON `payload`, `recorded_at`.
`DB::table()` writes them directly. An ActiveRecord model would add identity, events, casts and
timestamps over a row whose entire contract is that it is never mutated.

## What proves it

Every store extends its conformance suite from **DUR041** — the same cases the in-memory reference
and the DBAL bridge run. The journal suite joins the **replay tier**: a real workflow with an
activity, a timer and two side effects runs against this store and against `InMemoryEventStore`,
and what replay reads back is compared. A payload deformed by a round trip breaks replay silently,
not at write time, and that comparison is what catches it.

```
tests/unit/Bridge/Illuminate/  — 44 cases, four ports
```

## Not in this package

- **The resume lock.** Two workers replaying one execution duplicate its commands, and no storage
  choice prevents that. On Laravel it is `WithoutOverlapping` or an atomic `Cache::lock()`.
- **The Durable service provider.** Registering stores, binding ports, adding worker commands —
  that belongs to the Laravel integration package. A set of stores does not decide how an
  application wires them.

  The provider this package *does* ship, `DurableIlluminateServiceProvider`, registers nothing. It
  does the one thing no other package can do for it: tell Laravel where **its** migrations are.

## Install

```bash
composer require gplanchat/durable gplanchat/durable-bridge-illuminate
php artisan migrate
```

The four tables ship as a migration, loaded straight from the package — `migrate` is enough.
Publishing is for when you want to edit them, and from that point they are yours:

```bash
php artisan vendor:publish --tag=durable-migrations
```

`Schema\DurableSchema` still creates the tables on demand. That is what a test and a worker booting
on an empty database use; an application uses the migration. **Two ways to create the same four
tables is two chances to drift**, so `MigrationMatchesSchemaTest` renders the DDL of both — against
MySQL's grammar, which carries lengths and indexes where SQLite discards them — and compares them
statement for statement.

MIT. See [`LICENSE`](LICENSE).
