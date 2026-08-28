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

- **A queue, and jobs to put on it.** `Queue\ResumeLock` is the exclusion, not the plumbing: it
  takes a closure, so a job, an artisan command or a hand-written worker can all use it. Nothing
  here decides which.
- **The Durable service provider.** Registering stores, binding ports, adding worker commands —
  that belongs to the Laravel integration package. A set of stores does not decide how an
  application wires them.

  The provider this package *does* ship, `DurableIlluminateServiceProvider`, registers nothing. It
  does the one thing no other package can do for it: tell Laravel where **its** migrations are.

## One resume at a time

`Queue\ResumeLock` is the one thing no storage choice can supply. Two workers resuming the **same**
execution both replay it, both believe they are discovering the commands it produces, and those
commands go out twice. The journal does not prevent it — it faithfully records whatever it is
handed, twice included.

```php
$lock = new ResumeLock($cacheStore);          // any store implementing LockProvider
$lock->around($executionId, fn() => $runner->resume($executionId));
```

**It waits on its own rather than calling `Lock::block()`**, and that is deliberate: `block()` calls
a **global** `now()`, which only a full Laravel application defines — `illuminate/support` publishes
it under its own namespace only. A package that relies on it works inside an application and breaks
in a standalone worker or a test, which is the worst of both: the failure only happens where nobody
is looking.

**`LockProvider` is the only contract this lock needs, and it filters nothing.** An earlier version
of this file claimed it forced the caller to pick a store that can actually lock, and that the
`file` store did not implement it. **Both halves are wrong on Laravel 12**, and measuring said so:
nine stores implement `LockProvider`, `file` among them — and it locks correctly across processes —
while `NullStore` implements it too and its `NoLock::acquire()` returns `true` unconditionally.

Measured, twenty resumes of one execution across four `queue:work` processes:

| store | overlapping critical sections | max concurrency |
|---|---|---|
| `database` | 0 | 1 |
| `file` | 0 | 1 |
| `array` | **15 of 20** | **4** |
| `null` | **15 of 20** | **4** |

`array` excludes inside one process and not between two; `null` excludes nothing at all and says so
to nobody. Both type-check. **So the type is not the guard — the choice is yours, and it is the one
choice in this package that silently forks a journal when it is wrong.** Use `database`, `redis`,
`memcached`, `dynamodb` or `file`.

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

**Keep the published file's name.** Laravel keys migrations by basename and lets
`database/migrations` win the tie — that shadowing is exactly what makes your copy the one that
runs. Rename it and the two become two migrations, both run, and the second fails on
`table "durable_events" already exists` with the database half migrated.

`Schema\DurableSchema` still creates the tables on demand. That is what a test and a worker booting
on an empty database use; an application uses the migration. **Two ways to create the same four
tables is two chances to drift**, so `MigrationMatchesSchemaTest` renders the DDL of both — against
MySQL's grammar, which carries lengths and indexes where SQLite discards them — and compares them
statement for statement.

MIT. See [`LICENSE`](LICENSE).
