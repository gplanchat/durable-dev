# Spike #709: a database backend for the Magento bridge

Throwaway. Nothing under `src/` imports this directory, and no shipped package gained a dependency.
The public promise (the module's `conflict`, `ALLOWED.magento`, the backends page) is unchanged:
this is findings and a recommendation, for the user to decide on.

## What ran

- Mage-OS **2.2.0** (the bench's pin), PHP 8.2.34, in `mageos/`: its own `composer.json`, `vendor/`
  seeded from `magento/vendor`. `Gplanchat\Bridge\Dbal\` is autoloaded by path, because the module's
  `conflict` refuses the package itself (see Q5).
- Three containers, all `--rm` with a memory cap: `spike709-db` (MySQL 8.4, the shop, 2 GB),
  `spike709-journal` (MySQL 8.4, **a second server** for the journal, 1 GB), `spike709-os`
  (OpenSearch 2.12, 1.5 GB). The journal is on another server, not only another schema, so no
  Magento transaction can reach it.
- `Gplanchat_DurableSqlSpike` (`mageos/app/code/`): option A assembled like
  `DurableServiceProvider::bindIlluminate()` + `bindResumePath()`, a table queue, and
  `bin/magento durable-sql:spike setup|start|signal|work|status|nexus`.
- Scripts: `resolve.sh` (Q1), `run-restart.sh` (the key experiment), `probe-b.php` (option B).
  Their raw output goes to `out/`, which is not tracked.

## The key experiment (`run-restart.sh`)

`SpikeOrder`: `charge` (an activity that sleeps 6 s), an 8 s timer, a wait on the `approve` signal,
then `ship`. Worker 1 is `kill -9`ed inside `charge`; worker 2 is `kill -9`ed with the timer pending;
the signal is sent while **no worker runs**; worker 3 finishes the run. Output of the second run
(times UTC):

```
21:57:16 started order-restart
21:57:17 pid=2804168 charge:start               <- worker 1
21:57:19.651 killed worker 1 mid-activity
21:57:47 pid=2804347 take #2 activity spike.charge#1 delivery=2   <- lease ran out: 30 s after the take
21:57:53 pid=2804347 charge:end
21:57:55.905 killed worker 2 with the timer pending
21:57:56 signalled order-restart               <- no worker running
21:58:05 pid=2810323 ship order-restart by alice  <- worker 3: timer fired, signal consumed
21:58:05.397 completed
  ActivityScheduled 2, ActivityTaskStarted 2, ActivityCompleted 2, TimerScheduled 1,
  WorkflowSignalReceived 1, TimerCompleted 1, ExecutionCompleted 1
  epoch (DUR053 passes): 12    queue rows: 0
```

`charge` ran twice as a side effect (the killed attempt never reached its end) and is journalled
once: at-least-once execution, exactly-once outcome, as on the other backends. The first run gave
the same shape. The shop database holds 0 `durable_*` tables; the journal server holds all 7.

**Nexus is refused by name**, at both doors (`out/nexus.txt`):

```
register(): NexusUnsupportedByBackendException: A Nexus handler cannot be served by the magento-dbal
  backend: it has no route, [...] Use the Temporal backend to serve Nexus operations.
resume nexus-1: FAILED NexusUnsupportedByBackendException: The journal backend cannot serve a Nexus
  operation: [...] Use the Temporal backend to call Nexus operations.     -> run status "failed"
```

## Q1. Connection: A or B

| | A: DBAL from `env.php` `durable/db/url` | B: `db/connection/durable` via `ResourceConnection` |
|---|---|---|
| New packages (2.2.0) | 5: doctrine/dbal 4.5.0, doctrine/deprecations, symfony/lock, symfony/messenger, symfony/clock | none |
| Resolves against Mage-OS | yes on 1.3.0/PHP 8.2, 2.2.0/8.2, 2.3.0/8.4, 3.4.0/8.3, 3.4.0/8.5 (`resolve.sh ... deps`, exit 0 each) | n/a |
| Stores | `durable-bridge-dbal` unchanged | new family, ~1000 lines (the DBAL stores + schema are 1007, Illuminate's 825) |
| Nested transactions | DBAL savepoints | inner `rollBack()` then outer `commit()`: `Exception: Rolled back transaction has not been completed correctly` |
| Picking the connection | a DSN, fails loudly if wrong | `getConnection('durable')` silently returns the **shop's** connection (same `CONNECTION_ID()` 54, `db=magento`) unless `resource/durable` is also declared; only `getConnectionByName('durable')` reached the journal (id 164, `db=durable`) |
| DDL in a transaction | refused by `DurableSchema::ensure()` | Magento's adapter accepted a `createTable()` inside a transaction (MySQL commits implicitly) |

`symfony/messenger` and `symfony/clock` come only from `durable-bridge-dbal`'s own `require`
(its Messenger middleware); the stores need doctrine/dbal and, for the locks, symfony/lock. The
bench's lock has messenger 6.4.36 only because the Temporal bridge pulls it. The graph without A
(`resolve.sh 2.2.0 8.2.34 none`) differs from the graph with it by exactly those 5 lines.

What #695 learned applies to both: a host's migration tool runs on the default connection unless
told otherwise, so tables for a dedicated connection come from the first write or a setup command.

## Q2. Schema

`db_schema.xml` is **not** a way to reach a separate database. Measured with a table declared
`resource="durable"`:

- without `resource/durable` in `env.php`: `setup:upgrade` exits 0 and creates the table **in the shop's
  database**, silently;
- with `resource/durable => connection durable`: in default mode it lands on the journal server;
- in **developer mode** the same file fails: `Element 'table', attribute 'resource': [facet
  'enumeration'] The value 'durable' is not an element of the set {'default', 'checkout', 'sales'}.`
  A module cannot extend that XSD enumeration.

So the tables come from the bridge: `DurableSchema::setup()` (a `bin/magento durable:setup`, which
`durable-sql:spike setup` stands in for) or `ensure()` at the first write. That is the Symfony
answer for a dedicated connection, and what #695 documents for Laravel.

## Q3. Resume delivery and locks without Messenger

- **Carrier: a table on the journal's own connection** (`TableQueue`): `available_at` for delays,
  `SELECT ... FOR UPDATE SKIP LOCKED` to take, a 30 s lease instead of a delete, `ack` after
  handling. A worker killed mid-message loses nothing; the message comes back when the lease ends
  (measured: 28 s after the kill, 30 s after the take). Magento's MessageQueue stays out: DUR046
  measured MysqlMq running one message twice during a success and acknowledging undispatched
  messages after a crash, and its `queue_message` tables are on the shop's connection.
- **Timers must be `FireWorkflowTimersMessage`**, handled by `FireWorkflowTimersHandler`. The spike
  first copied `LaravelWorkflowTimerDispatcher` (a timer as a delayed plain resume) and spun: 1146
  passes in 15 s on a due timer that never fired, because in distributed mode only
  `FireWorkflowTimersHandler` calls `checkTimers()`. See follow-up 3.
- **Per-execution lock**: symfony/lock `DoctrineDbalStore` on the journal connection (a TTL row in
  `lock_keys`, 30 s), taken non-blocking; a held lock re-queues the message 1 s later. The activity
  attempt claim is `LockActivityAttemptClaim` on the same store, reused unchanged. A killed holder
  keeps its row until the TTL. Magento's `LockManagerInterface` was not used: its database backend is
  `GET_LOCK` on the **shop's** connection, and DUR046 found it answers `true` without locking when
  the database is unavailable.
- **DUR053 fence**: unchanged, it is `DbalEventStore::claimPass()`/`appendFenced()` on the journal
  connection; 12 passes claimed in the run above. Under B the same SQL works through Magento's
  adapter: a claim's `UPDATE` held the heads row and a `LOCK IN SHARE MODE` read in another process
  waited 2.53 s for it (`probe-b.php claim` / `fenced-read`), but the code would have to be rewritten.
- DUR050 is honoured: a resume that arrives before its outcome is re-queued, not failed.

## Q4. Conformance

Under A, yes, unchanged: the four DBAL conformance classes against the journal server (MySQL 8.4),
`DURABLE_TEST_DSN=mysql://...:33710/durable_test vendor/bin/phpunit --fail-on-skipped --filter
ConformanceTest tests/unit/Bridge/Dbal`: **OK (60 tests, 356 assertions)**, fenced passes included
(`expectsFencedPasses()` is true); the whole DBAL directory: OK (114 tests, 488 assertions). This
proves the adapter on that server, not the Magento wiring; the restart experiment covers that.
Under B, the abstract cases in `src/Durable/Testing` would be reusable, but only after a new adapter
exists, and only inside a Magento bootstrap: like `phpstan-magento`, CI's bench job alone could run them.

## Q5. Cost of the public change (option A)

- `src/DurableModule/composer.json`: drop `gplanchat/durable-bridge-dbal` from `conflict` (keep
  illuminate), add it to `suggest`. Today `resolve.sh 2.2.0 8.2.34 bridge` fails with
  `gplanchat/durable-magento ... conflicts with gplanchat/durable-bridge-dbal`.
- `hugo-docs/layouts/index.html` and `index.fr.html`: `ALLOWED.magento` gains `'dbal'`; `WHYNOT.magento`
  and `FWNOTE.magento` are rewritten in both languages.
- `documentation/user/backends/_index.md` and `_index.fr.md` (the NOTE at lines 17-22 of each);
  `src/DurableModule/README.md` ("reaches in-memory and Temporal, and nothing else"); the Magento
  mentions in `documentation/user/configuration` EN and FR (a new `durable/db/url` row).
- `UPGRADE.md`: a section at the end of "Unreleased". Additive, nothing breaks, but the promise
  changes.
- DUR046 says "Magento has no native journal and will not get one": a new ADR supersedes that part
  (DUR000, a human decision).
- CI (supervised): `magento-matrix` resolves with the DBAL bridge; `magento-boot` needs a second
  MySQL service to run the restart experiment.

## Recommendation: A

A reuses the stores, the schema, the DUR053 fence and the attempt claim that already pass the
conformance suites, and its dependency cost resolves on every Mage-OS line the module admits. B
adds no package but means a second SQL family of ~1000 lines whose tests can only run inside a
Magento bootstrap, over an adapter that refuses nested rollbacks, commits DDL implicitly, and
hands back the shop's connection when a resource name is missing. B's one real advantage, the
declarative schema, does not hold: it fails in developer mode. The new code A needs is small: the
table queue, the worker loop, `durable:setup`, and the wiring in `RuntimeFactory`.

## Found on the way

- **The published module does not install without the Temporal bridge**, which it only suggests:
  `setup:install` dies on `Class "Gplanchat\Bridge\Temporal\Http\Psr18Http" does not exist`, from
  `RuntimeFactory`'s optional `?Psr18Http $jsonGateway`, because Magento's `ClassReader` reflects
  every constructor type. The spike installs the Temporal bridge to get past it. CI does not see it:
  the matrix only resolves, and the bench installs the Temporal bridge.
- `durable_workflow_runs.waiting_on` stays "activity spike.ship attempt 1 in flight" after completion.

## Proposed follow-up tickets (not opened; the user decides first)

1. **ADR: Magento may journal to SQL on a connection of its own**: supersede DUR046's "no native journal" (human).
2. **Bug: durable-magento cannot be installed without durable-bridge-temporal**: no Temporal type in `RuntimeFactory`'s constructor.
3. **Bug (to confirm): Laravel `illuminate` timers are plain resumes**: check a `sleep()` wakes on the Laravel bench; send `FireWorkflowTimersMessage`.
4. **durable-bridge-dbal: a table queue**: `durable_queue` in `DurableSchema`, lease + `available_at`, PostgreSQL/SQLite too, with its own conformance.
5. **durable-magento: SQL wiring**: `durable/db/url` in `env.php` builds the DBAL stores; both DSNs set is refused by name.
6. **durable-magento: `durable:worker` for SQL and `durable:setup`**: the spike's loop (lock, DUR050 deferral, attempt claim, timers).
7. **durable-magento: admin grid and run page on `DbalWorkflowRunCatalog`.**
8. **Resume lock on MySQL**: TTL row vs a `GET_LOCK` store on the journal connection (released when the worker dies); measure both.
9. **CI (supervised)**: matrix resolution with the DBAL bridge; boot job runs the restart experiment on a second MySQL.
10. **Public promise change**: `conflict`, `ALLOWED`/`WHYNOT`/`FWNOTE` EN+FR, backends and configuration pages EN+FR, module README, UPGRADE.
11. **DBAL projection clears `waiting_on` on completion.**
12. **Optional: durable-bridge-dbal without symfony/messenger**: move the middleware out, two packages fewer on Magento.
