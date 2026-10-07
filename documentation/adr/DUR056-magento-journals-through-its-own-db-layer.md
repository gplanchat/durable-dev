# DUR056: Magento journals through its own DB layer on a dedicated connection, with no Nexus

## Status

Accepted by the user on 2026-09-30.

On 2026-09-30 the user chose option B of spike #709, which goes through Magento's own database
layer; option A goes through Doctrine DBAL. The measurements below come from that spike, draft PR
#723. The same day the user settled decision 3. When `resource/durable` names the shop's `default`
connection, the backend boots and logs a warning, as in DUR054 decision 6.

Related:
- [DUR046](DUR046-magento-a-tier-1-host-that-improved-the-core.md), the Magento host. This ADR
  supersedes part of it, listed under Decision, and leaves its text unchanged.
- [DUR054](DUR054-the-journal-does-not-share-the-applications-connection.md): the journal (the
  append-only sequence of events that records everything an execution decided and received; see the
  [glossary](../user/glossary/_index.md)) does not share the application's connection. Decision 3
  below applies that rule to Magento.
- [DUR051](DUR051-a-backend-refuses-what-it-cannot-honour.md): a backend that cannot perform an
  operation throws an exception that names it. Decision 5 applies that rule to Nexus operations
  (operations served by another service, which a workflow calls the way it calls an activity; see
  the [glossary](../user/glossary/_index.md)).
- [DUR053](DUR053-a-superseded-pass-cannot-write.md): the fencing epoch, which the new event store
  implements. A pass (one replay of an execution by a worker) claims the next epoch of its
  execution when it starts, and its appends carry that epoch, so an older pass can no longer write.

## Context

DUR046 gave Magento two backends, `memory` and `temporal`, "final rather than provisional", because
`ResourceConnection` is neither Doctrine DBAL nor Illuminate's connection. It also states "Magento
has no native journal and will not get one". The user reopened the question on 2026-09-29. The new
target is Durable on a database, as on Symfony and Laravel, with a connection of its own and no
Nexus.

Spike #709 compared two ways to get that connection:

- **A:** a Doctrine DBAL connection built from its own `env.php` entry, `durable/db/url`, with the
  `durable-bridge-dbal` stores (the classes that persist the journal, metadata and queues)
  unchanged.
- **B:** a connection declared through Magento (`db/connection/durable` plus `resource/durable`),
  read through `Magento\Framework\App\ResourceConnection`, with a new family of stores over
  `Magento\Framework\DB\Adapter\AdapterInterface` (`Pdo\Mysql`).

The spike measured Magento's adapter on Mage-OS 2.2.0 and MySQL 8.4 (`probe-b.php`):

- An inner `rollBack()` followed by the outer `commit()` throws `Rolled back transaction has not
  been completed correctly`.
- A `createTable()` inside a transaction runs without error, and MySQL commits the transaction
  implicitly.
- Unless `resource/durable` is also declared, `getConnection('durable')` silently returns the
  **shop's** connection (same `CONNECTION_ID()`, database `magento`).
  `getConnectionByName('durable')` returns a connection to the journal's server.
- `db_schema.xml` with `resource="durable"`: without `resource/durable` in `env.php`,
  `setup:upgrade` exits 0 and creates the table in the shop's database. With it,
  developer mode fails to validate the file, because `resource` is an XSD enumeration (`default`,
  `checkout`, `sales`) that a module cannot extend.
- The SQL of the DUR053 fence works through the adapter. A pass claim's `UPDATE` (the statement
  that raises the execution's epoch) held the heads row, the one row per execution that stores
  its epoch. A `LOCK IN SHARE MODE` read from another process waited 2.53 s for that row.

The spike did not build the B stores. An extrapolation from the DBAL stores and schema (1007
lines) and from Illuminate's (825) puts them at about 1000 lines of new code.

## Decision

1. **Magento may journal to SQL, through its own DB layer.** The stores use `AdapterInterface`,
   obtained from `ResourceConnection`. Doctrine DBAL and Illuminate stay out of the module, and the
   SQL path adds no Composer package.
2. **The connection is a declared resource.** The backend reads `resource/durable` from `env.php`
   through `DeploymentConfig` and resolves the connection it names with `getConnectionByName()`.
   The recommended target is a dedicated `db/connection/durable`.
3. **The backend never falls back silently to the shop's connection.** If `resource/durable` is not
   declared, or names a connection absent from `db/connection`, the backend fails at boot with an
   exception that names the missing key. It never calls `getConnection('durable')`, so it never
   takes the shop's connection that call returns. When `resource/durable` names `default`, the
   backend boots and logs a warning that names it, as DUR054 decision 6 does on Symfony and Laravel.
   On `default`, the journal and the shop share one adapter. A workflow started inside a shop
   transaction, such as an observer during checkout, then hits the nesting constraint below and
   fails.
4. **Declaring both `resource/durable` and `durable/temporal/dsn` fails at boot** with an exception
   that names both keys.
5. **No Nexus.** Registering a Nexus handler throws `NexusUnsupportedByBackendException`, and so
   does calling a Nexus operation, as on the other journal backends (DUR051).
6. **Resumes, timers and activities go through a leased table on the journal's connection.** A
   resume is the message that makes a worker replay an execution. Magento's MessageQueue is not
   used. Timers travel as `FireWorkflowTimersMessage`. The spike first sent them as delayed plain
   resumes and ran 1146 passes on a timer that never fired.
7. **The locks also use the journal's connection.** The per-execution resume lock and the activity
   attempt claim are each a TTL row or a `GET_LOCK` on that connection. The choice between them
   follows the measurement in #732, still open. Magento's `LockManagerInterface` is not used. DUR046
   objected to its database backend, `GET_LOCK` on the **shop's** connection, which returns `true`
   without locking when the database is unavailable.

### Design constraints from the adapter's measured behaviour

None of the measured flaws above rules out B. The implementation must handle each one, with a test:

- **An inner rollback throws.** A store never nests transactions. Each unit of work (a pass claim,
  a fenced append, a queue take) opens exactly one transaction, and throws if
  `getTransactionLevel()` is not 0 when it starts. A failure rolls back the whole unit.
- **DDL inside a transaction is committed implicitly.** No store issues DDL. Only the setup command
  below creates tables, and it exits with an error when a transaction is open.
- **`getConnection('durable')` falls back to the shop's connection.** Decision 3 covers it. A test
  boots with `db/connection/durable` and no `resource/durable`, and expects the boot exception that
  names the missing key.
- **`db_schema.xml` cannot target the journal.** The module declares no Durable table in
  `db_schema.xml`.

### How the schema is created

`bin/magento durable:setup` creates the schema through the adapter's DDL API (`newTable()`,
`createTable()`, `isTableExists()`, `tableColumnExists()`, `addColumn()`), on the connection of
decision 2, outside any transaction. The command is idempotent and additive. It creates what is
missing and adds any column a later version needs. At runtime, a store that finds a table missing
throws an exception that names the missing table and points to `durable:setup`.

Three other ways to create the tables do not fit Magento:

- **Creating tables at the first write**, as `DurableSchema::ensure()` does on DBAL. The first
  write is a store's own unit of work (a pass claim, a fenced append, a queue take), which runs in
  a transaction, and MySQL then commits that transaction implicitly.
- **A Setup patch.** Magento records an applied patch in `patch_list`, in the shop's database. A
  journal re-pointed to an empty database never gets its tables again, and a patch that runs while
  `resource/durable` is missing writes to the shop's database, as `db_schema.xml` does.
- **`db_schema.xml`.** The XSD measurement above rules it out.

### The two DUR046 statements this ADR supersedes

This ADR supersedes these two statements of DUR046 and no others:

- "Magento has no native journal and will not get one";
- "Magento reaches `memory` and `temporal`, and this is final rather than provisional".

The `conflict` on `gplanchat/durable-bridge-dbal` and `gplanchat/durable-bridge-illuminate` stays,
since Magento uses neither. DUR046's measurements also stand, and decisions 6 and 7 rest on them.
MySQL-backed MessageQueue ran one message twice during a success and acknowledged undispatched
messages after a crash, and `LockManagerInterface`'s database backend works on the shop's
connection.

## Rejected alternative: option A, Doctrine DBAL

Option A builds a DBAL connection from the `env.php` entry `durable/db/url` and reuses the
`durable-bridge-dbal` stores unchanged. The spike ran it with the journal on a second MySQL server.
A workflow with an activity, an 8 s timer and a signal completed through two `kill -9`s of the
worker and a signal sent with no worker running. The DBAL conformance classes, the shared test
cases every store adapter runs, passed there (60 tests). Option A added 5 packages
(doctrine/dbal, doctrine/deprecations, symfony/lock, symfony/messenger, symfony/clock), which
resolve on every Mage-OS line that the module's Composer constraint allows. The spike recommended
it.

The user rejected it because Magento manages its database through its own layer, and the journal
follows that layer. The results above remain in PR #723, and none of them was measured under B.

## Consequences

- B needs a new family of stores over `AdapterInterface`, about 1000 lines: the event store with
  the DUR053 fence, metadata, the run catalogue and its projection, parent links, the attempt
  claim, the table queue. Each one has its own ticket under epic #740.
- The shared conformance cases in `src/Durable/Testing` must run against these stores inside a
  Magento bootstrap. `composer test` does not load Magento, so only the Magento bench (the
  repository's test Magento application) and CI's Magento jobs exercise them.
- The restart experiment, the conformance suites and the Nexus exception check, which A passed on
  DBAL, have to pass again on B.
- On 2026-09-30 the user set the rule that an application uses the same API whatever the backend
  and the host, with one accepted exception, a functional limit of Nexus. The Magento SQL backend
  follows that rule, and "no Nexus" (decision 5) is the exception. The same day, the user also
  accepted that Magento cannot resolve an attribute on a constructor parameter. That is a limit of
  the host's object manager, not of this backend, and it changes none of the decisions above.
- The parity audit of the same day found two gaps on Magento: no dispatcher
  (`WorkflowResumeDispatcher`, the port that starts a run on every backend), and no signal delivery
  on the application side. They stay open under this ADR. PR #782 proposes an OpenSpec change that
  closes them with one client API on every backend and host, through a repository per workflow.
- The public documentation changes: the picker, the backends and configuration pages (EN and FR),
  the module README, and an UPGRADE entry. #739 tracks those changes. They land last, in a
  pull request separate from this ADR's.
