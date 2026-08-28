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
- [x] 1.3 **Probe whether the configured store locks across processes.** It does not, and the task
      under-estimated the problem: the bridge's own documentation was **wrong in both directions**.

      `README.md`, the `ResumeLock` docblock and the `composer.json` suggest all claimed that
      `LockProvider` *"forces the caller to pick a store that can actually lock"*, and that
      *"the `file` store does not implement it"*. On Laravel 12, **nine** stores implement it —
      `ArrayStore`, `DatabaseStore`, `DynamoDbStore`, `FailoverStore`, `FileStore`,
      `MemcachedStore`, `MemoizedStore`, `NullStore`, `RedisStore`. `file` is among them **and it
      locks correctly across processes**. And `NullStore` is among them, whose `NoLock::acquire()`
      is `return true;`.

      Measured — twenty resumes of one execution, four `queue:work`, 200 ms of critical section:

      | store | overlapping sections | max concurrency | verdict |
      |---|---|---|---|
      | `database` | 0 / 20 | 1 | excludes |
      | `file` | 0 / 20 | 1 | excludes — **the documentation said it could not** |
      | `array` | **15 / 20** | **4** | per-process only |
      | `null` | **15 / 20** | **4** | excludes nothing, silently |

      Max concurrency is the worker count in both failing rows: the lock is not slowing anything
      down, it is absent. That is a forked journal and duplicated activities — the exact failure
      DUR030 names — from a one-word cache setting.

      **The three files are corrected in this slice.** A claim that the type system guards you is
      worse than no claim: it is the reason nobody checks the setting.

      **The decision the task asked for, and it splits in two.**

      - **`null` is refused at boot, always.** There is no deployment in which a lock that always
        grants is correct, so there is no risk of a wrong refusal.
      - **`array` is *not* refused at boot, and is refused by the worker command.** Refusing it at
        boot would break every application test suite, because `array` is Laravel's own default
        cache in the testing environment and excluding correctly inside one process is exactly what
        a test needs. What cannot be right is `array` under a command whose whole purpose is to be
        one of several processes. The knowledge lives where the plurality does.
- [x] 1.4 **Measure class discovery.** A thousand generated classes in `App\Domain`, five of them
      carrying `#[Workflow]`, four strategies, PHP 8.2.33.

      | strategy | 100 classes | 1 000 classes | classes loaded | peak memory |
      |---|---|---|---|---|
      | explicit list | 0,16 ms | **0,14 ms** | 334 | — |
      | text scan, then reflect the hits | 0,97 ms | 9,6 ms | 334 | — |
      | reflection scan | 1,5 ms | 15,0 ms | **1 334** | **+0,9 MB** |
      | cached manifest | 0,02 ms | 0,03 ms | 334 | — |

      **The explicit list is flat**, because it reflects only what was declared — the application's
      size does not enter into it. The scans are linear, at roughly 15 µs per class reflected and
      9,6 µs per class read.

      **The reflection scan's real cost is not in the milliseconds column.** It loads the whole
      application to find five classes: 1 334 declared classes against 334, and +0,9 MB, in *every*
      process — every request, every worker. That is the argument against scanning, and it does not
      get better with a faster machine.

      **The decision: no `durable:cache` command.** The manifest is genuinely the fastest thing
      measured and it wins **0,11 ms** over the explicit list. A command that earns a tenth of a
      millisecond is a command to write, invalidate, document and get wrong on deploy. And the
      framework already has one: `php artisan config:cache` caches `config/durable.php` itself, so
      a manifest would be a second caching mechanism for a file Laravel already caches.
- [x] 1.5 **Measure the collision rate.** 160 resumes spread over K executions, four
      `queue:work`, 200 ms of critical section, MySQL 8.4 for the queue and the lock. A *collision*
      is a resume that finds the lock held **on its first attempt** — later attempts are counted
      separately, because they measure the backoff rather than the contention.

      | concurrent executions | collisions | later re-queues | wall |
      |---|---|---|---|
      | 1 | **98,8 %** | 8 641 | **148,5 s** |
      | 4 | 63,1 % | 459 | 21,3 s |
      | 16 | 5,0 % | 0 | 8,9 s |
      | 64 | 0,6 % | 1 | 8,9 s |

      **A methodological correction worth keeping.** The first run of this table read 0,0 % at
      K = 4, 16 and 64 — and it was an artefact of the harness, not a property of the queue. Seeding
      round-robin (`$i % $executions`) makes *neighbouring* queue entries belong to different
      executions, so four workers popping four consecutive jobs pop four different executions **by
      construction**. Randomising the assignment is what a real queue looks like, and the zeroes
      became 63 %, 5 % and 0,6 %. A measurement that produces exactly zero deserves suspicion
      before it deserves a paragraph.

      **The answer to the question the task asked: both, and the split is the point.**

      In the shape an application usually has — many workflows in flight, a handful of workers —
      contention is a rounding error: 0,6 % at sixteen executions per worker. If that were the whole
      picture, §1.2's conclusion would be over-engineering.

      It is not the whole picture, because **durable execution attracts the opposite shape**: one
      long-lived execution woken again and again by signals, timers and activity results. There,
      98,8 % of resumes collide, and the cost is not the collision — it is what waiting does with
      it. The 1 s release backoff turned 32 s of work into **148 s of wall clock**, and blocking
      would instead have held all four workers hostage to one execution.

      So the non-blocking entry point from §1.2 is justified by the **hot-execution** case, not the
      average one, and §4.1 inherits a second requirement from this table: the backoff has to be a
      knob, because at 98,8 % collisions it *is* the latency.

## 2. The package boots

- [x] 2.1 `src/DurableLaravel/` exists: `composer.json` naming `gplanchat/durable-laravel` under
      `Gplanchat\Durable\Laravel\` — the family shape of `durable-bundle` and `durable-plugin`,
      not the `Bridge\` shape, because this is an integration and not a store — its
      `extra.laravel.providers` entry, a `LICENSE` at the prefix root, a README, and the monorepo
      wiring: path repository, `@dev` require, PSR-4 map, `phpstan.neon` **and** `psalm.xml`.

      **The splitsh prefix waited for its repository, and got it.** Adding the line first is
      precisely what turned the Splitsh job red on every push to `main` for
      `durable-bridge-illuminate`; `gplanchat/durable-laravel` was created before the line landed.
      The third step of that lesson is not ours to take: the fine-grained `SPLITSH_PUSH_TOKEN` lists
      its repositories explicitly, so a repository created after it is not in it — and the symptom
      is a **403 on a repository that exists**, not a 404.

      **And wiring `psalm.xml` uncovered that PR #165 never did.** That PR reported adding
      `src/Bridge/Illuminate` to Psalm's `projectFiles`; the file on `main` has no such line, and
      `git log -S` finds it was never there. The `sed` that was supposed to insert it did not match
      the file's indentation, the commit carried only the `InvalidOperand` cast, and CI stayed green
      because nothing had entered the analysed set. **The bridge has never been Psalm-analysed until
      this slice.** Both directories are added here, and Psalm reports no errors on either.
- [x] 2.2 `DurableServiceProvider` binds the four ports from `config/durable.php`, publishable
      under `--tag=durable-config`, found by package auto-discovery. Six tests, and the provider
      registers into a **bare container** — no Laravel application around it — which is the same
      discipline `ResumeLock` follows for the same reason.

      **One choice of backend binds all four ports**, by a single `match` rather than four
      independent settings: a journal on one backend under a catalogue on another is not a
      configuration, it is a fault. A backend this package does not serve is refused **by name**, at
      registration, naming both of the two it does.

      **§1.3's decision is now code.** `null` is refused at `boot()` — it grants every lock, in every
      deployment — while `array` passes, because it is Laravel's own testing default and excluding
      inside one process is what a test wants. The worker command will be the one to refuse it.

      **Two bugs the slice found, both worth their line.**

      1. **The defaults file cannot call `env()`.** `illuminate/support` ships the helper, but it
         resolves through `PhpOption\Option`, which only `vlucas/phpdotenv` provides — present in an
         application, absent in a standalone worker or a test. The first version of
         `config/durable.php` used `env()` for all five settings and every test died on
         `Class "PhpOption\Option" not found`. **This is the failure the `ResumeLock` docblock
         already describes about `Lock::block()`**, met a second time in the same package, from the
         other direction. The published copy may use `env()` freely; the package's own defaults may
         not, and the file now says so.
      2. **A container binding does not need its interface to exist.** The provider bound
         `WorkflowRunCatalogInterface` from `Gplanchat\Durable\Store\`, where it is not — it lives
         in `Gplanchat\Durable\Port\`. Six tests passed anyway: `::class` does not autoload, and
         `$app->make()` on a registered string key never resolves the name. **Psalm caught it, and
         no test could have.** The slice that put Psalm on this code found a bug in the same slice's
         code, which is the argument for §2.1's second paragraph.
- [x] 2.3 A workflow class written for `durable-bundle` runs unmodified, resolved by the name its
      attribute declares. Four tests.

      The `workflows` key of `config/durable.php` names the classes, and the provider feeds them to
      the core's `WorkflowRegistry` — which already indexes each class **twice**, under the alias its
      `#[AsWorkflow]` attribute declares and under its FQCN, so a resume carrying either resolves.
      Nothing had to be written for that half; it is what §1.4 measured as the cheap answer, and it
      is why the fixture in this slice imports nothing but `Gplanchat\Durable\`. *"Runs unmodified"*
      is checkable rather than promised: the class has no Laravel and no Symfony symbol in it.

      **The half the core cannot supply is the error message.**
      `WorkflowRegistry::getHandler()` throws `Unknown workflow type: X` — it names the type and
      stops, because the core has never heard of a `config/durable.php`. `DeclaredWorkflowTypes`
      wraps it and names the type, the config key, the publish command and what *is* declared.

      A message that names the failure without naming the remedy makes a reader open the source of
      an installed package, and that is the whole distance between the two throws.

      **It keeps its own list rather than asking the registry**, which would have needed a
      `registeredTypes()` accessor on a core class — a change to the Symfony path to improve a
      Laravel message. The list is the configured array the provider already holds.

## 3. Work rides Laravel's queue

- [x] 3.1 Activity dispatch as an `illuminate/queue` job on the application's own connection.
      `LaravelActivityTransport` implements the same port `MessengerActivityTransport` does, with
      the same shape — `enqueue` pushes, `dequeue` pops and acknowledges — and a different
      vocabulary: `later()` for a `DelayStamp`, `pop()` for a `ReceiverInterface`. Five tests.

      **The pull half is implemented rather than stubbed.** In production nobody calls it —
      `queue:work` pushes the job into `handle()` — but a synchronous drain (a test, a command that
      empties the queue by hand) has the right to exist, and an `isEmpty()` that answered *true*
      over a full queue would make its caller conclude there is nothing left to do. It also has to
      **keep what it popped to answer**: the first version dropped the job it had just taken, which
      answers correctly once and loses work on every call after.

      **No queue traits on the job.** `Queueable` and `InteractsWithQueue` exist for `dispatch()`
      and `release()`; this job is pushed by the transport and never re-queues itself, so the
      package still needs nothing but `illuminate/contracts`. The resume job will need them — that
      is where `illuminate/queue` enters, in §4.
- [x] 3.2 Workflow resume as a job, drained by `php artisan queue:work` and nothing else.
      `LaravelWorkflowResumeDispatcher` has the same shape as its Messenger counterpart: a resume is
      a message, a new run saves its metadata **first** and then becomes one — a resume that arrived
      before them would not know what to replay.

      **What the Symfony side gets from a `DispatchAfterCurrentBusStamp`, this one gets from the
      queue — and that is a condition, not a given.** On the `sync` connection `push()` runs the job
      on the spot, so a resume that dispatches another resume recurses in the same process until the
      stack ends. `ExecutionRuntime` already names this hazard in a docblock; here it is one `.env`
      value away.

      So `sync` is **refused at boot**, beside the `null` lock store, and for the same reason: there
      is no deployment in which it is right for this backend, so the refusal cannot be wrong. Three
      tests, one of them the refusal.
- [x] 3.3 Timers on the queue's own delay, the way the DBAL backend rides Messenger's `DelayStamp`.
      A `retryDelay` becomes `later((int) ceil($seconds))` — rounded **up**, because waiting less
      than asked is the only error that counts here — and is then stripped from the message.

      That last half is the contract the in-memory and Messenger transports already keep, and it is
      not decoration: a delay that survived being queued would be waited a second time, by the
      worker that receives it.
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
