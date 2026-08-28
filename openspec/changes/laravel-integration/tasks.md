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

      **The refusal reads the configured driver name, not the connection's class, and that is a CI
      constraint made visible.** The first version tested `instanceof SyncQueue`, which meant
      requiring `illuminate/queue` — and Laravel 11+ pulls `symfony/process ^7.2` through it. The
      `Symfony 6.4.* / PHP 8.4` matrix job died on the spot: *"illuminate/queue v12.68.0 requires
      symfony/process ^7.2.0 … not loaded, likely because it conflicts with another require"*.

      **The Laravel integration and the Symfony 6.4 line cannot share one dependency graph.** That
      is a fact about Laravel, not a CI quirk. Reading `queue.connections.<name>.driver` says the
      same thing without the dependency, and says it without having to resolve the connection to
      judge it — so the package still needs nothing beyond `illuminate/contracts` on that path.
- [x] 3.3 Timers on the queue's own delay, the way the DBAL backend rides Messenger's `DelayStamp`.
      A `retryDelay` becomes `later((int) ceil($seconds))` — rounded **up**, because waiting less
      than asked is the only error that counts here — and is then stripped from the message.

      That last half is the contract the in-memory and Messenger transports already keep, and it is
      not decoration: a delay that survived being queued would be waited a second time, by the
      worker that receives it.
- [x] 3.4 A worker killed mid-activity resumes from the journal, and an activity whose result was
      already recorded does not run twice. Measured on the harness — a real queue, a real
      `kill -9`, a three-second activity that writes to disk on every actual run.

      **The wiring this needed first.** `ResumeWorkflowJob` carried its message and did nothing
      with it, so no execution could advance. It now hands it to `ResumeWorkflowHandler` — the
      core's, which left the Symfony bundle for `Gplanchat\Durable\Handler\` precisely so a host
      without a message bus could serve it. This package assembles it; it does not write a second
      one. A timer became a deferred resume on the queue's own delay, and `ActivityExecutor` and
      `ActivityMessageProcessor` had to be bound too — without them the first activity died on
      *Target [ActivityExecutor] is not instantiable*.

      | | journal | execution | disk witness |
      |---|---|---|---|
      | killed mid-activity | `ActivityScheduled`, `ActivityTaskStarted` | not complete, 1 job held | 1 start, 0 finish |
      | after a worker returns | + `ActivityTaskCompleted`, `ActivityCompleted`, `ExecutionCompleted` | **complete** | 2 starts, 1 finish |
      | the same activity re-delivered | unchanged, still five events | complete | **unchanged** |

      **The activity re-ran, and that is the correct answer.** Its result was never recorded — the
      process died between the start and the finish — so replay has nothing to skip. The claim the
      task makes is about an activity whose result *was* recorded, and that is the third row: the
      re-delivered job returned in **7.76 ms** against three seconds for a real run, wrote nothing,
      and left the journal at five events. `ActivityMessageProcessor`'s first gesture is to ask the
      journal whether this activity already has a terminal outcome.

      **And one operational finding that cost the first run.** A job whose worker was killed stays
      **reserved** until `retry_after` — 90 seconds by default. A worker started with
      `--stop-when-empty` inside that window sees an empty queue and exits **having done nothing**,
      which reads exactly like a resume that failed. It is not: it is a resume that has not been
      offered the job yet. A supervised worker, which outlives the window, picks it up. §6 owes
      this a sentence, because the symptom is indistinguishable from a bug.

## 4. One execution, one replay

- [x] 4.1 Wrap the resume job in `Queue\ResumeLock`, in the shape §1.2 measured to be right:
      **`tryAround()`**, which runs the work if the turn is free and returns `false` without waiting
      if it is not. `around()` stays for callers that want to block; a queue worker is not one.

      The lock now says only that the turn is taken. What the caller does about it — defer, give up,
      log — is the caller's decision, which is the whole difference §1.2 measured: `around()` held
      **fifteen worker-seconds for four seconds of work**, and its wait window is a queue-depth
      ceiling dressed as a latency knob.

      **The job re-dispatches itself rather than calling `release()`, and both reasons matter.**
      `release()` needs the `InteractsWithQueue` trait — Laravel checks for the trait itself, not
      for a `setJob()` method — so it needs `illuminate/queue`, which pulls `symfony/process ^7.2`
      and makes this package irreconcilable with the Symfony 6.4 line the neighbouring matrix still
      tests. But the packaging is the smaller half: §1.2 measured that **`release()` consumes an
      attempt**, so at `--tries=5` fifteen resumes out of twenty landed in `failed_jobs` having
      never run — contention became indistinguishable from a bug. A fresh job carries a fresh
      budget, and `tries` goes back to counting crashes.

      The price is real and named: nothing bounds the deferral on the queue's side any more.
      `ResumeDeferral` bounds it, with `lock.backoff` and `lock.max_deferrals` — §1.5's reading,
      since on a hot execution the backoff *is* the latency — and giving up **throws**, because an
      endless deferral looks exactly like an execution that is progressing.
- [x] 4.2 A misconfigured lock store is refused, and §1.3's answer gained its second half.

      `null` was already refused at boot: it grants every lock, in every deployment. What §1.3 left
      open was `array`, which it declined to refuse because it is Laravel's own testing default and
      excluding inside one process is exactly what a test wants.

      **That reasoning holds for the in-memory backend and collapses for `illuminate`.** A resume
      runs in a worker process separate from whatever dispatched it, so two `array` locks never see
      each other — 15 overlapping critical sections out of 20, measured in §1.3. It is therefore
      refused at boot **when the backend is `illuminate`**, and still accepted under `memory`. Two
      tests hold both halves.
- [x] 4.3 Two workers, one execution, one journal — proved by a test, not by the lock's existence.
      A turn already held by another worker: the resume **replays nothing**, the execution stays
      incomplete, and the job comes back on the queue carrying its deferral count. A free turn
      replays and queues nothing. And the lock is **released** afterwards, so the next resume gets
      its turn instead of waiting out the five-minute TTL — the one failure a lock that works can
      still cause.

## 5. Temporal, decided rather than deferred twice

- [x] 5.1 Take `design.md`'s three ways out to a decision with a number behind it. **The decision is
      to split**, and it is its own change — not this one.

      **What it installs into a Laravel application.** Measured with `composer require --dry-run` on
      the harness, which already carries the library, the Illuminate bridge and this package: **8
      packages, 5 of them Symfony** — `messenger`, `var-exporter`, `dependency-injection`,
      `filesystem`, `config` — for **~36 MB on disk**, of which `dependency-injection` alone is 23.
      The other three, `grpc`, `protobuf` and `common-protos`, are the gRPC client and are earned.

      A Laravel application loads none of the five. There is no service provider, no bundle, no
      Messenger: they sit in `vendor/` so that four Messenger transports and a DI extension can
      exist for somebody else.

      **How small the coupled part actually is.** The bridge is 759 PHP files. The Symfony-coupled
      ones are `Messenger/` (5), `DependencyInjection/` (1), `Resources/` (1) and the bundle at the
      root — **8 files, one percent of the package, carrying 36 MB for the other 99 %.**

      **What a split would break, and the answer is: less than it looks.** `durable-bundle` already
      reaches into the bridge's Symfony classes — `DurableTemporalTransportFactoryPass` and
      `DurableExtension` both name `Gplanchat\Bridge\Temporal` — without requiring the package. The
      Symfony wiring is therefore *already* spread across the two, and moving the eight files into
      `durable-bundle` consolidates rather than divides. For a Symfony user who has both packages,
      the visible change is one line out of `bundles.php`.

      That is a breaking change, and the repository has the machinery for one: `durable-upgrade.php`
      renames what Rector can rename, `UPGRADE.md` documents what it cannot, and nothing is tagged
      past `v0.1.0-alpha7`. Doing it here would mean editing the Temporal bridge and the Symfony
      bundle from inside the Laravel change — two packages this change has no business touching —
      so it gets its own, with an ADR behind it.

      **Until it lands, the package keeps refusing `temporal` by name.** Not because the third way
      out was chosen, but because it is the only one that costs nothing to reverse, and the split is
      what reverses it.
- [x] 5.2 Whatever is chosen, the site's chooser and the Packages page say the same thing as the
      code — and today **they do not**.

      `ALLOWED.laravel` offers `['memory', 'temporal', 'illuminate']`. The package serves
      `['illuminate', 'memory']` and refuses the third by name at registration. A visitor who picks
      Laravel + Temporal is handed a command that installs a combination the provider rejects on the
      first boot.

      The Packages page is clean — its Laravel line names the library and the Illuminate bridge, and
      no sentence there claims Temporal on Laravel.

      **The chooser is the canvas's, and the canvas is under an open pull request (#211).** Removing
      `temporal` from `ALLOWED.laravel` is one entry in one table, but it must be made in the canvas
      and regenerated, or the guard added by that same pull request turns the build red. It is
      therefore recorded here and made in the canvas as soon as #211 is merged — one line, in the
      change that owns the file.

## 6. What the documentation owes

- [x] 6.1 Name `durable-workflow/workflow`, in both languages, inside the package's own section —
      not in a footnote. It is described as what it is: durable execution on Laravel queues, `yield`
      as the checkpoint, its own storage, no server, a thousand stars, **good at what it does**, and
      *"if an engine on your existing queue is what you want, take it."*

      Then the difference, which is the only thing worth saying next to it: this package sells the
      **backend choice**, and a workflow class written for `durable-bundle` runs here unmodified.
      That is the claim the other package does not make.

      Two neighbouring names on Packagist deserve the sentence rather than the hope that nobody
      notices.
- [x] 6.2 Packages and Backends carry the package, in both languages.

      **The Packages page gains a full section** — install, configuration, one choice of backend
      binding every port, workflows declared rather than scanned with the measurement behind it, and
      two tables that had nowhere to live before: the three settings refused rather than tolerated
      (`null` always, `array` under `illuminate`, the `sync` connection), and the two behaviours that
      read like bugs and are not (the `sqlite` driver hosting one worker, and a killed worker's job
      reserved until `retry_after`).

      **The Backends page gets the section it was refused**, and for the reason the refusal
      predicted: while only the stores existed there was no way in, because that page is organised
      around `durable.event_store.type`. There still is not — and now that is the point rather than
      the objection. A Laravel application does not read that YAML; `gplanchat/durable-laravel` binds
      the ports through its own published config, and the page says so and links to it.

      **The chooser is not done, and cannot be here.** `ALLOWED.laravel` still offers `temporal`,
      which §5.2 recorded; the table lives in the canvas, and the canvas is under an open pull
      request (#211). One entry, in the change that owns the file, as soon as it merges.
- [x] 6.3 OST004's two Laravel rows — §5 and the summary in §7 — are struck through and marked
      done, pointing at the ADR.

      **[DUR047](../../../documentation/adr/DUR047-laravel-the-host-that-measured-before-it-wired.md)**
      is the ADR, and it is indexed. It records the five measurements that preceded the first line of
      the package, the three of them that *replaced* a design rather than confirming it, each refusal
      with its number, and the Temporal decision.

      It also records a non-event worth naming: unlike DUR046, where a Tier 1 host improved the core
      three times, this one needed **one** addition — `ResumeLock::tryAround()`, in the bridge — and
      no change to `Gplanchat\Durable\` at all. `ResumeWorkflowHandler` had already moved to the
      core so a host without a message bus could serve it. That is the conformance suites and the
      ports doing their job.
