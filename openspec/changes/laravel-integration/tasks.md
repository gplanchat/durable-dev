# Tasks

## 1. A harness, and the four things it has to answer

A Tier 1 bootstrap has no unit test that proves it boots. Before the package has a shape, a throwaway
Laravel skeleton has to answer the four questions `design.md` records as assumed — and each answer
either confirms a design or replaces it.

- [x] 1.1 A Laravel skeleton on this machine with `gplanchat/durable` and
      `gplanchat/durable-bridge-illuminate` installed by path repository, `artisan migrate` creating
      the four tables. Throwaway by default; tracking it is §6.3's question, not this one.
      **Done, on Laravel 12.68.0 / PHP 8.2.33, and it works unmodified**: package auto-discovery
      finds `DurableIlluminateServiceProvider` from `extra.laravel.providers` with nothing declared
      by hand, and `artisan migrate` creates `durable_events`, `durable_workflow_metadata`,
      `durable_workflow_runs` and `durable_child_workflow_parent_link` with the columns the schema
      promises. Two packages, two path repositories, `minimum-stability: dev` — no other change to
      a stock skeleton.

      **And the harness immediately paid for itself, on a claim the README makes.** "Publishing is
      for when you want to edit them, and from that point they are yours" is **true**, and true for
      a mechanism worth writing down rather than trusting: `Migrator::getMigrationFiles()` does
      `->keyBy(fn ($file) => $this->getMigrationName($file))`, and `BaseCommand::getMigrationPaths()`
      returns `array_merge($this->migrator->paths(), [$this->getMigrationPath()])` — package paths
      first, `database/migrations` last. A duplicate key keeps the last, so the published copy
      **shadows** the package's. Measured: a column added to the published copy appears in the
      table, and the migration still runs exactly once.

      **The corollary is a trap, and it is one keystroke away.** The shadowing holds only while the
      two files share a basename. Renaming the published copy to a fresh timestamp —
      `2026_08_28_000000_create_durable_tables.php`, the instinct of anyone who has written a
      Laravel migration — makes them two different migrations, and both run:

      ```
      0001_01_01_000000_create_durable_tables ....... DONE
      2026_08_28_000000_create_durable_tables ....... FAIL
      SQLSTATE[HY000]: General error: 1 table "durable_events" already exists
      ```

      The database is left half migrated. The README and the Packages page said *publish to edit*
      without saying *keep the name*; this task adds the sentence, in three files and two languages.
- [x] 1.2 **Measure what a waiting job costs.** Twenty jobs on one execution, four
      `queue:work` processes, MySQL 8.4 for the queue and the lock store.

      **First, the substrate.** The measurement cannot be run on the skeleton's default SQLite:
      four workers popping the `jobs` table produce `SQLSTATE[HY000]: General error: 5 database is
      locked`, and three of the four workers die on their first job — with WAL enabled and a 60 s
      busy timeout. That is a finding in its own right, and §6 owes it a sentence: **a Laravel
      application cannot run more than one Durable worker on the default `sqlite` driver.**

      | | wall | worker-s held | `handle()` calls | failures |
      |---|---|---|---|---|
      | `around()`, 200 ms work | **4.47 s** | 14.98 | 20 | 0 |
      | `release(1s)`, 200 ms work | 19.26 s | **4.20** | 210 | 0 |
      | `release(0)`, 200 ms work | 4.07 s | 4.79 | **1 918** | 2 |
      | `around()`, 800 ms work | 16.41 s | 59.83 | 23 | 0 — **3 `LockTimeoutException`** |

      The work itself is 4 s and 16 s of critical section respectively, so the third column is the
      cost of waiting, not of working. **The trade is real and it is symmetric:** blocking buys
      latency with worker slots (11 s of the 15 held are pure waiting), releasing buys worker slots
      with latency (the release delay *is* the latency floor), and releasing with no delay buys
      both at the price of 96 queue round-trips per job.

      **Two failure modes neither shape advertises, and both matter more than the numbers.**

      1. **`release()` consumes an attempt.** With a finite `tries`, contention is
         indistinguishable from a bug: at `--tries=5`, **15 of 20 jobs landed in `failed_jobs`**
         having never run — they simply lost the draw five times. Making it work needs `tries = 0`,
         which spends the retry budget the queue exists to give you. The table above uses
         `tries = 0` plus `maxExceptions = 3` for the release rows; the naive version is the 15
         failures.
      2. **`waitSeconds` is a queue-depth ceiling, not a latency knob.** Once *depth × work*
         exceeds it, `around()` starts throwing. At 800 ms × 20 jobs — 16 s of queued work against
         a 10 s default — 3 jobs threw `LockTimeoutException` and were saved only by their retries.
         Nothing in the name says the default caps a *queue*, and a shop whose activities take a
         second will meet it.

      **The conclusion, which §4.1 implements:** neither shape as written. `ResumeLock` needs a
      **non-blocking entry point** — an `around()` that returns rather than waits when the lock is
      held — so the job decides what to do with its turn instead of the lock deciding for it. With
      it, `tries` goes back to meaning *crashes*, the release delay is a tuning knob rather than a
      workaround, and the ceiling in failure mode 2 disappears because nobody waits inside a
      worker slot.
- [ ] 1.3 **Probe whether the configured store locks across processes.** Confirm `array` type-checks
      as a `LockProvider` and fails to exclude across two worker processes. Decide whether the
      provider can refuse it at boot, and whether that refusal can be wrong (a single-worker
      deployment where `array` is correct).
- [ ] 1.4 **Measure class discovery.** Boot cost of an explicit `config/durable.php` list against a
      scan, on an application with a hundred classes and none of them workflows. Whether a cached
      manifest earns a command depends on this number.
- [ ] 1.5 **Measure the collision rate.** Under a realistic worker count, how often two resumes of
      one execution are dequeued together. This decides whether 1.2 is the design or a detail.

## 2. The package boots

- [ ] 2.1 `src/DurableLaravel/` with a `composer.json` naming `gplanchat/durable-laravel`, its
      `extra.laravel.providers` entry, a `LICENSE` at the prefix root (DUR020 requires it of every
      split), and the prefix added to `bin/splitsh-publish.sh` — with its satellite repository
      created *before* the line lands, the lesson of `durable-bridge-illuminate`.
- [ ] 2.2 A `DurableServiceProvider` found by package auto-discovery that binds the four storage
      ports from `config/durable.php`, publishable under `--tag=durable-config`.
- [ ] 2.3 A workflow class written for `durable-bundle` runs unmodified, resolved by the name its
      attribute declares. An undeclared type fails naming itself and where types are declared.

## 3. Work rides Laravel's queue

- [ ] 3.1 Activity dispatch as an `illuminate/queue` job on the application's own connection.
- [ ] 3.2 Workflow resume as a job, drained by `php artisan queue:work` and nothing else.
- [ ] 3.3 Timers on the queue's own delay, the way the DBAL backend rides Messenger's `DelayStamp`.
- [ ] 3.4 A worker killed mid-activity resumes from the journal, and an activity whose result was
      already recorded does not run twice.

## 4. One execution, one replay

- [ ] 4.1 Wrap the resume job in `Queue\ResumeLock`, in whichever shape §1.2 measured to be right.
- [ ] 4.2 A misconfigured lock store is refused or reported, per §1.3's answer.
- [ ] 4.3 Two workers, one execution, one journal — proved by a test, not by the lock's existence.

## 5. Temporal, decided rather than deferred twice

- [ ] 5.1 Take `design.md`'s three ways out to a decision with a number behind it: what
      `durable-bridge-temporal` actually installs into a Laravel application, and what a split would
      break for those already on it.
- [ ] 5.2 Whatever is chosen, the site's chooser and the Packages page say the same thing as the
      code. Today they cannot, because the code does not exist.

## 6. What the documentation owes

- [ ] 6.1 Name `durable-workflow/workflow`. OST003 §3 makes this a duty, not a courtesy: a reader
      who wants an engine-on-queues should be sent to it, and the comparison should be one this
      project would accept if it were written about it.
- [ ] 6.2 Packages, Backends and the home page chooser carry the package — and the Backends page
      gets the section it was refused while only the stores existed, because by then
      `event_store.type` is not the only way in.
- [ ] 6.3 Correct OST004 §5: *"a bootstrap, plus a fourth adapter family"* was already half stale
      when this change opened, and fully so when it lands. Leave an ADR behind, per the house rule.
