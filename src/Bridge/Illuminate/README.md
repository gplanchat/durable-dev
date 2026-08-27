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
- **A service provider.** That belongs to the Laravel integration package, not to a set of stores.
- **Published migrations.** `Schema\DurableSchema` creates the four tables on demand, which suits a
  worker starting on an empty database and a test. A Laravel application will want
  `php artisan migrate`, and that is the next thing this package owes it.

## Install

```bash
composer require gplanchat/durable gplanchat/durable-bridge-illuminate
```

MIT. See [`LICENSE`](LICENSE).
