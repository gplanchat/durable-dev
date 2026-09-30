# DUR054: The journal does not share the application's connection

## Status

Accepted by the user on 2026-09-30. An agent drafted it; it was merged as Proposed on 2026-09-29
(#691); #693 and #695 carry its implementation.

The user decided the direction on 2026-09-29: sharing a database connection between Durable's
stores and the application's business code is **strongly discouraged**. The user settled the three
choices that the first draft left open the same day; they are decisions 5 to 7 below.

Related:
- [DUR030](DUR030-dbal-backend-simplified-durable-execution.md) introduced the SQL backend. It is
  not amended, and it never made the claim this ADR retires: it says nothing about transactions.
- [DUR053](DUR053-a-superseded-pass-cannot-write.md) introduced the fencing that a shared
  connection puts at risk. It is not amended.
- [DUR047](DUR047-laravel-the-host-that-measured-before-it-wired.md), the Laravel host.

## Context

Durable's SQL stores — `gplanchat/durable-bridge-dbal` and `gplanchat/durable-bridge-illuminate` —
take a connection from the host, and both hosts default to the application's own:

- Symfony: `durable.dbal.connection` defaults to `doctrine.dbal.default_connection`
  (`src/DurableBundle/DependencyInjection/Configuration.php`);
- Laravel: `connection => null` in `config/durable.php` resolves to the default connection
  (`DurableServiceProvider`).

Documentation written after DUR030 turned that default into a selling point: on one connection, the
journal append and the business write would "land in one transaction", so an activity that wrote
and then died could not be replayed. The claim is in the Illuminate bridge README, the
`IlluminateEventStore` docblock, `config/durable.php`, the `durable-laravel` README, the Illuminate
section of the packages page (English and French), OST003 §3, and the landing page.

Two facts undo it.

1. **Durable never implemented that guarantee.** No code in the core, the bridges or the hosts wraps
   an activity's execution and the journal append in one transaction. The guarantee existed only if
   the application opened that transaction itself, around code Durable runs. Durable's own
   transactions are the fencing ones: `claimPass()` and `appendFenced()` each run
   `transaction()` / `transactional()` on the store's connection.
2. **Those fencing transactions assume they commit.** DUR053 §4 requires that "a newer pass reads
   the history only after its claim has committed". On a connection the application may already
   have a transaction open on, Durable's `transaction()` becomes a nested one. What it thinks is a
   commit only closes an inner scope, and the outer transaction decides.

What follows from sharing, as mechanisms:

- **A business rollback erases the journal.** An outer rollback undoes everything inside it,
  including appends and claims Durable treated as done. The execution's history loses events a
  worker already acted on, which is the forked-journal failure DUR030's lock and DUR053's fence
  exist to prevent.
- **A claim is not visible when Durable thinks it is.** Until the outer transaction commits, other
  workers do not see the new epoch, so a stale pass keeps writing.
- **Locks live as long as business code does.** The heads row DUR053 locks on MySQL and PostgreSQL
  stays locked for the whole business transaction, and other resumes of that execution wait on it.
- **Business code reaches Durable's tables.** Same connection, same credentials: nothing separates a
  query on `orders` from one on `durable_events`. The journal's integrity then depends on every
  line of business code, not on Durable's.
- **Connection state leaks both ways.** Isolation level, session variables and a Doctrine
  `EntityManager` closed after an exception belong to the connection, not to whoever changed them.

What an activity that writes and dies actually needs is not a shared transaction. It needs the
at-least-once contract every durable engine has: an activity may run again, so its side effects are
keyed to be idempotent.

## Decision

1. **The recommended setup is a dedicated connection** for Durable's stores. On Symfony, a Doctrine
   DBAL connection of its own, named in `durable.dbal.connection`. On Laravel, a connection
   of its own in `config/database.php`, named in `durable.connection`.
   - On Symfony this costs only configuration. `DurableSchema` already keeps the journal's tables
     out of the ORM's schema when they live on another database; there they come from the first
     write or from `bin/console durable:setup`, not from `doctrine:migrations:diff`.
   - On Laravel it does not, yet. The shipped migrations run on the default connection, whatever
     `durable.connection` names: `php artisan migrate` builds the tables in the application's
     database, the stores create their own copies on the journal's at the first write, and every
     later schema migration misses the journal's database. Fixing that is a condition of this
     recommendation, listed below.
2. **Sharing the application's connection is strongly discouraged.** The documentation says so
   wherever it shows the connection setting, with the mechanisms above.
3. **The shared transaction is no longer a feature.** Every place listed under Context stops selling
   it. Where the documentation needs an answer to "an activity wrote and then died", the answer is
   idempotent activities.
4. **Durable never opens a transaction that spans business code**, and never documents doing so as
   a pattern.
5. **The defaults stay.** Symfony's `durable.dbal.connection` keeps
   `doctrine.dbal.default_connection` and Laravel's `durable.connection` keeps `null`. Three
   options were weighed: keep the defaults, require an explicit connection with no default, or
   default to a connection named `durable`. The last two break every application that sets nothing,
   for a practice this ADR discourages rather than forbids. The recommendation and the defaults
   therefore disagree, and decision 6 is what surfaces the disagreement.
6. **A warning, not a refusal.** When the journal's connection is the application's default one,
   Durable logs a warning: at container compile time on Symfony, at boot on Laravel. It names the
   connection, says why sharing it is discouraged, and says how to name one of its own. Nothing
   stops: an application that shares its connection on purpose keeps working.
7. **A database and credentials of Durable's own are recommended, not enforced.** The
   documentation recommends a database (or a schema) and a database user for the journal, so that
   business code cannot reach its tables at all. Nothing in the code checks it.

## Consequences

- An application that follows the recommendation gets fencing that behaves as DUR053 describes:
  claims and appends commit when Durable says they do.
- An application that shares its connection keeps working as today. It gets a warning that names
  the risk, and the documentation writes the risks down where it chose the connection.
- No BC break: the defaults do not move, and the warning changes no behaviour.
- The pitch for the SQL backend becomes "one SQL database, no cluster to run", which is what DUR030
  decided.

### Follow-up, not in this ADR's pull request

- Remove the shared-transaction claim from: `src/Bridge/Illuminate/README.md`,
  `src/Bridge/Illuminate/Store/IlluminateEventStore.php` (docblock), `src/DurableLaravel/config/durable.php`,
  `src/DurableLaravel/README.md`, `documentation/user/packages/_index.md` and `_index.fr.md`
  (Illuminate section), `documentation/ost/OST003-php-ecosystem-integrations.md` §3,
  `hugo-docs/layouts/index.html` and `index.fr.html`, and the `hugo-docs/variant-b-narrative*`
  drafts.
- Make the shipped Laravel migrations run on `durable.connection`, with an UPGRADE entry for the
  applications that already set it.
- Show a dedicated connection in the configuration examples of both hosts.
- Emit the warning of decision 6, on both hosts, with a test each.
- Recommend a database and a user of Durable's own where the documentation shows the connection
  setting (decision 7).
