# Tasks

## 1. Build the instrument, and probe what the design guessed

A Tier 1 bootstrap has no unit test that proves it boots. The bench **is** the test harness, so it
comes first — and while it is being built it answers the four questions `design.md` records as
unmeasured.

- [x] 1.1 Track the `magento/` overlay the way `sylius/` is tracked. **The task was wrong about
      what that means.** "Sources yes, `vendor/` no" is not enough: with `vendor/` already excluded
      by the root `.gitignore`, `git add -An magento` still stages **10 178 files** — `dev/` alone
      brings 7 256 — all of them written by composer. `sylius/` is 220 files because a Sylius
      skeleton *is* project code; the Magento equivalent is eight files of overlay.
      So `magento/.gitignore` inverts the rule: ignore everything, re-allow the overlay by name.
      A file composer adds tomorrow stays out without anyone thinking about it, which an exclusion
      list cannot do. Verified: 8 files tracked, and three simulated distribution files change
      nothing. OST004's row corrected.
- [x] 1.2 `composer install` reaches a working `bin/magento`. **It already did, and the task's
      evidence was wrong.** "No `vendor/magento/framework`" looked at the wrong path: Mage-OS ships
      under `vendor/mage-os/`, where 363 packages sit. `bin/magento --version` answers *Mage-OS CLI
      2.2.0 (based on Magento 2.4.8-p4)*, and `setup:db:status` answers *All modules are up to
      date* — the application is installed, not merely downloaded.
      What was actually broken is the bench's **ports**. Its defaults collide with the benches
      beside it: MySQL on 3306 is held by `sylius-mysql-1`, and Temporal on 7233 by the
      `temporal server start-dev` the integration suite runs. Magento was therefore talking to
      Sylius's database server and being refused. Defaults moved to 33306 and 7234, the stack
      comes up whole, and `.env.example` says why.
      `check-php-extensions.sh` was a claim; it is now a measurement: all eighteen present on
      PHP 8.2.33, exit 0.
- [x] 1.3 **What a dying consumer leaves behind: silence** — measured, `magento/probe-queue.php`
      plus a bench-local probe module — it stays out of the published package. The row stays `IN_PROGRESS` with `number_of_trials = 0`, a
      `queue_lock` row survives the dead process, no dead letter, nothing logged, and a fresh
      consumer waits 25 s beside it without taking it. Recovery needs **two** cron jobs —
      `mysqlmq_clean_messages` (twice a day, after a **24 h** `retry_inprogress_after`) and
      `messagequeue_clean_outdated_locks` (hourly) — and **their order decides whether the message
      runs or is swallowed**: a retry that lands while the lock stands makes `Consumer` acknowledge
      it *without dispatching*, `COMPLETE`, handler never called. Redelivery restarts the handler
      **from the beginning**. The shipped defaults are saved by their own sloth; a shop that
      shortens the retry delay is not. §4.3 is where this gets pinned.
- [x] 1.4 **Whether `LockManagerInterface` is shared across processes. It is** — measured, two
      processes, `magento/probe-lock.php`. The bench configures `lock.provider: db` explicitly;
      `Magento\Framework\Lock\Backend\Database` sits behind a `Lock\Proxy`, and a second process
      is refused a lock the first holds. **A killed holder releases it** — `GET_LOCK` dies with its
      connection, so a crashed consumer does not wedge an execution.
      **But the backend answers `true` without locking anything when `isDbAvailable()` is false**
      (read, not measured), which is the shape the startup refusal of §2.3 has to catch: a lock that
      always says yes is worse than none.
- [x] 1.5 **A long-poll transport does not starve the consumer — it duplicates the message.**
      Measured. A handler held a message 200 s and finished normally: the runner sets no deadline,
      and the bench's MySQL `wait_timeout` is 28800 s. But the retry timer looks only at
      `updated_at`, never at whether the first consumer is done: with the delay shortened to a
      minute, **two live processes ran the same message at once** (pids 442111 and 445235). A
      worker holds its message by construction and outlives any delay, so **the worker cannot be a
      queue message** — §5.1's `bin/magento` commands stop being a preference. And Magento's queue
      offers no mutual exclusion at all, which makes §1.4's lock the only thing between two
      consumers and a forked journal.

## 2. The module boots

- [x] 2.1 `src/DurableModule` with `registration.php` declaring `Gplanchat_Durable`, `etc/module.xml`,
      and a `composer.json` naming `gplanchat/durable-magento`. `bin/magento module:status` lists it.
- [x] 2.2 The bench's path repository resolves. **Two host constraints found on the way**, both
      recorded in `design.md`: Mage-OS's `composer-dependency-version-audit-plugin` refuses a path
      package that also exists on Packagist, and Magento's generated `Interceptor` cannot extend a
      `final` class — which is the house style everywhere else in this repository.
- [x] 2.3 **Composer refuses the SQL bridges; no code does.** `gplanchat/durable-magento` declares
      `conflict` on `gplanchat/durable-bridge-dbal` and `gplanchat/durable-bridge-illuminate`.
      Measured on the bench: `composer require gplanchat/durable-bridge-dbal` ends in *"Conclusion:
      remove gplanchat/durable-magento (conflict analysis result)"* and writes nothing. The
      incoherent installation never exists, so no process boots into it.
      **Author's decision on PR #172**, replacing a first version that had built the refusal in
      code — a constraint the package manager can express does not belong in a runtime that only
      learns of it after the wrong thing is installed. Consequence: the module has **no backend
      configuration surface**, so there is nothing to mistype; §5 is where a second backend, and
      therefore a choice, starts to exist. What `conflict` cannot carry is the *reason* — that stays
      in `ALLOWED.magento`, the selector, and `design.md`.

## 3. Workflows and activities are discoverable

- [x] 3.1 **`di.xml` carries two arrays; the contract is not one of them.** Workflow classes are
      named, activity handlers are placed, and the factory reads each handler's interfaces and keeps
      the ones carrying `#[ActivityMethod]` — one declaration fewer to get wrong, and the names stay
      the attributes'. It reuses the same two core objects the bundle's compiler pass does;
      `PayloadToContractMethodInvoker` moved from `durable-bundle` to `durable` for it, since two
      hosts now need it (**BREAKING CHANGE**) — and it ships its migration procedure, as the rule
      requires: a cumulative `durable-upgrade.php` Rector set, an `UPGRADE.md` at the repository
      root, and the one thing Rector cannot do written out (a compiled Symfony container keeps the
      fully-qualified name and wants its `cache:clear`).
      **The refusal is the mechanism**: `MagentoRuntime::run()` used to self-register an unknown
      workflow, which made declaration meaningless and left `Scenario: An undeclared workflow fails
      at the moment of the mistake` false since 3.2. It now throws naming the class and the `di.xml`
      argument — the scenario is discharged at the bench, and the demo command's three hand-written
      closures are gone.
- [x] 3.2 A workflow class written once runs unmodified on the in-memory backend inside the bench.
      `bin/magento durable:demo ORD-4242` runs charge → reserve → notify in order and exits 0.
      `PlaceOrderWorkflow` imports nothing from Magento — no `ObjectManager`, no
      `ResourceConnection` — which is the whole point: everything under the ports is
      `gplanchat/durable` unchanged.

## 4. ~~The queue carries the work~~ — abandoned, and here is what measured it

**Author's decision, 28/08.** Nothing of Durable rides Magento's `MessageQueue`, because on the only
durable backend this host reaches there is nothing for it to carry.

`TemporalWorkflowCommandBuffer` schedules an activity as a
`ScheduleActivityTaskCommandAttributes` — a Temporal command on a Temporal task queue.
`EventStoreCommandBuffer`, which puts an `ActivityMessage` on the host's queue, is the
**non-Temporal** path. Magento has no native journal and will not get one (`memory` and `temporal`,
decided). So with Temporal the host's queue carries neither activities nor resumes; and with
`memory` everything is one process, where a queue serves nothing that survives it.

Task 4 and task 5 were never a sequence — they were alternatives, and only one of them is reachable
here. §5.3 had already measured the consequence without naming it: the execution stuck because its
activity had been dispatched in-process, and the answer is a Temporal activity worker, not a Magento
topic.

- [x] ~~4.1 the four XML files~~ — the `request` type was measured first and the finding stands on
      its own (`design.md`): Magento's encoder **empties** a transport object without throwing, and
      `string[]` drops associative keys. Both are recorded; the XML is not written.
- [x] ~~4.2 the five roles as handlers~~ — the resume orchestration went to `gplanchat/durable`
      instead, behind `WorkflowTimerDispatcher`, where six non-Symfony hosts share it.
- [x] ~~4.3 one resume at a time~~ — §1.4 measured `LockManagerInterface` shared across processes
      and that stands; what needed the lock was two consumers on one queue, and there is no queue.
      ⚠ If a host-native journal is ever added, this entry comes back with it.

## 5. Temporal, end to end

- [x] 5.1 **Where the journal lives is decided by the presence of a DSN.** `RuntimeFactory`
      (renamed from `InMemoryRuntimeFactory`) assembles an `InMemoryEventStore` without one and a
      `TemporalJournalEventStore` with `durable/temporal/dsn` in `env.php` — a connection string,
      not the backend-name surface §2.3 removed. It stays constructible without Magento, which puts
      the decision under CI. **The worker is built**: `bin/magento durable:worker` polls the journal
      task queue and completes workflow tasks, bounded by `--max-tasks` and `--time-limit` for the
      supervisor that restarts it. A command and not a queue consumer, because §1.5 measured what
      Magento does to a message held too long. ⚠ **The grid still reads `running` for every run,
      and the worker is not the reason**: a `DurableJournal` workflow is long-lived by construction.
      A truthful status must come from the journal's events, not from the Temporal workflow status —
      the history cursor is wired for it, and the dashboard change owns the rest.
- [x] 5.1b **An admin screen: `System > Durable processes > Process history`.** Route, ACL, menu,
      controller, block and template, reading `InMemoryWorkflowRunCatalog` over whatever event store
      the factory assembled — the same core observation the Sylius dashboard renders, so nothing is
      reimplemented. Verified over HTTP in both states: without a DSN it says so and explains why an
      empty list is the correct answer; with one, the warning goes and it reads the cluster.
      *Landed here on the author's instruction rather than in the separate dashboard change; that
      change remains the home for run detail, filters and backend health.*
      ⚠ **A catalog is not derivable from a journal**, and the first version got this wrong:
      `InMemoryWorkflowRunCatalog` keeps its own map, fed by `recordStart()`/`recordOutcome()` in
      the process that executes. An admin request executes nothing, so the grid was empty while a
      run had just completed against the cluster. Listing a cluster's executions means asking the
      cluster — `TemporalWorkflowRunCatalog`, which the bridge already ships.
- [ ] 5.2 The bench's `compose.yaml` Temporal stack runs a workflow from an order placed in the
      storefront to a completed execution visible in the Temporal UI.
- [ ] 5.3 The failure OST003 names — **half measured, and the half that holds is the one that
      matters**. Killed with `kill -9` mid-reservation and restarted under the same execution id,
      three times: **the card is charged exactly once**. The journal replays what it recorded. What
      does *not* yet happen is the execution running to completion — `WorkflowStuckException`,
      because `reserve` was dispatched into the dead process's in-memory activity transport and
      nothing in the new process will settle it. ⚠ **Resuming needs durable activity dispatch**,
      which is task 4. This entry stays open, and it now says exactly what closes it.
      Instrument: `magento/probe-resume.php` plus a slow, charge-recording workflow in the bench
      probe module — the §3.1 declaration mechanism used by a third party, which proves it too.
      Found on the way: **the core imported the Symfony bundle** (`TimerWakeDelayCalculator`), a
      fatal error on any host without it. Moved to `Gplanchat\Durable\Timer\`, with its migration
      procedure, and a guard over the 183 files of `src/Durable` so it cannot come back.

## 6. Say it landed

- [ ] 6.1 An ADR for the decisions this change makes — the package name, the two-backend scope, the
      lock. A change that lands leaves an ADR behind.
- [ ] 6.2 The home page selector drops the `?` from `gplanchat/durable-magento`, and its state stops
      being `planned`. Through the canvas, not the generated file.
- [ ] 6.3 `documentation/user/packages/` and the guide's Backends page carry Magento, in both
      languages.
- [ ] 6.4 OST003 §Magento and OST004 §5 record what was actually built, and OST004's Magento row
      leaves the *"not built yet"* table.
