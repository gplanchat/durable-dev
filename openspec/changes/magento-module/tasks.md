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

## 3bis. What the published package must not carry

- [x] 3bis.1 **The demo left the package.** `PlaceOrderWorkflow`, `OrderActivities`,
      `DemoOrderActivities` and `durable:demo` moved to the bench module; the published `di.xml`
      declares **no workflow at all**, its two arrays empty with a commented example of where a
      project adds its own. An integration module has no business making every consumer carry
      workflows that are not theirs.
      The module's unit tests got their own fixtures under `tests/unit/DurableModule/Fixture/`,
      which is where test material belonged in the first place — they exercise the declaration
      mechanism, not any particular workflow.

- [x] 3bis.2 **One PSR-4 root, and the special case disappears.** The module is
      `Gplanchat_DurableModule`, the package autoloads under `Gplanchat\DurableModule\`, and the
      second `psr-4` entry that existed only for `Controller/` is gone. Magento composes an admin
      action from the *module name*; once that name and the PSR-4 root agree there is nothing extra
      to declare. Author's decision — the earlier shape treated the symptom.

- [x] 3bis.3 **The admin screen uses Magento's standard grid, and gained a detail view.**
      The first version was a hand-written `<table>` in a template — not a decision, just the
      shortest thing that rendered. It is now a `ui_component` listing over a custom
      `AbstractDataProvider`, which is the documented way to feed a grid from something that is not
      an SQL collection: the operator gets the paging, bookmarks, column controls and export they
      know, and none of it is reimplemented.
      ⚠ **Paging is the friction, and it is bounded rather than hidden**: the grid pages by offset,
      the cluster by continuation cursor, and the two do not translate without state. The provider
      reads a **200-run window** and pages inside it; the way out, when it bites, is to remember
      cursors per page in the admin session — not a bigger window. Filtering says the same thing:
      it filters the window, not the cluster, whose visibility query is a surface of its own.
      The detail view (`durable/process/view`) reads `readHistory()` — the same port the Sylius
      dashboard renders — and shows the run's journal: 23 events for a completed order on the bench.

- [x] 3bis.4 **The status filter is a multi-select, and the filters actually filter.** `status`
      becomes a `select` column whose options come from `WorkflowRunStatus::cases()` — the enum is
      the source, so an added status appears in the filter without anyone remembering to add it —
      and `listing_filters` carries the core's `ui/grid/filters/elements/ui-select` template, which
      is what turns one choice into several. `addFilter()` therefore accepts both shapes the widget
      sends: a string for one box ticked, an array for several.
      ⚠ **Two bugs, both found by measuring rather than by reading.** The action column rendered
      empty cells without raising, because `foreach ($x['data']['items'] ?? [] as &$item)` takes a
      reference into a *temporary* — the `??` has to go, replaced by an `isset()` guard. And every
      filter was a no-op because `getData()` still ran the first version's single `workflowName`
      branch and never called the new `applyFilters()`: dead code that looked like live code.
      Measured on the bench, 18 runs: `completed` → 5, `running` → 13, `completed,running` → 18,
      `failed` → 0, `failed,cancelled` → 0, `workflow_name ~ slow` → 5.

- [x] 3bis.5 **Chaque ligne du journal se déplie, et la frise dit l'attente.** L'écran de détail
      répondait « quoi » et jamais « avec quoi », parce que le port ne portait pas la réponse :
      `WorkflowRunEvent` n'avait que séquence, horodatage, voie et libellé. Il gagne un
      `details` en fin de constructeur — additif, la classe est `final readonly`, aucun appelant
      existant ne bouge. Le journal le remplit avec `Event::payload()`, qui est **sur l'interface**
      et n'a donc rien coûté ; le pont Temporal sérialise les attributs de l'événement d'historique,
      qui sont un `oneof` — le nom du champ renseigné se lit sur `getAttributes()`, ce qui évite
      d'énumérer cinquante formes.
      ⚠ **Les charges utiles seraient arrivées en base64** (`Payload.data` est un champ `bytes`) :
      elles sont relues par-dessus avec `Codec/JsonPlainPayload`, celui-là même qui les a écrites.
      Mesuré sur la grappe : 16 événements sur 16 dépliables, et le `durableAppend` montre
      l'événement métier qu'il transporte — `ActivityScheduled`, `durable.demo.charge`,
      `{"orderId": "ORD-4242"}` — au lieu d'un bloc opaque.
      ⚠ **La frise a fait tomber un défaut du pont** : `recordedAt` ne gardait que
      `getSeconds()` de l'horodatage Temporal. Seize événements séparés de quelques millisecondes se
      lisaient au même instant, et une frise construite là-dessus empile tous ses repères au même
      endroit. Les nanosecondes sont désormais tronquées à la microseconde, là où PHP s'arrête.
      La frise elle-même est du CSS : une voie par nature, un repère par événement placé à
      `(t - t₀) / durée`. Sur une commande du banc, 23 événements sur 24 secondes : **91 % de la
      frise est un trou** entre la planification de la tâche et son démarrage — un fait que la liste
      de 23 lignes régulièrement espacées cachait activement.
      ponytail: des repères, pas des barres. Une barre relierait la planification d'une activité à
      sa complétion, or le port ne porte pas de quoi les corréler ; ce sera le port qu'il faudra
      ouvrir, pas le gabarit.

## 4bis. What the CI can see of Magento

- [x] 4bis.1 **A Mage-OS × PHP matrix, the counterpart of the Symfony one.** Five entries, each an
      edge with a reason: the oldest line that still accepts the module's PHP floor, the bench's
      pin at that floor, the top of the 2.x line under a recent PHP, the 3.x floor — where Mage-OS
      refuses PHP 8.2 while the module allows it — and newest on newest. It proves the module's
      constraints are honest against each line; it does not prove boot, which costs ~1 GB per
      entry and belongs to an integration job.
      Verified to discriminate before it was written: `2.2.0` on PHP 8.2 resolves, `3.4.0` on PHP
      8.2 fails naming the cause.
- [x] 4bis.2 **A job that boots it.** One job, not matrixed — the distribution is ~1 GB and the
      install takes minutes, which is exactly why there is one edge and not five. MySQL and
      OpenSearch as services, `composer install` through the bench's tracked lock so it installs
      **this commit** and not a published version, then `setup:install`, `module:status`,
      `durable:demo` asserting both `notify:charge:ORD-4242` **and** `durable.demo.charge` — the
      second is what proves the activity names come from the contract's attributes — and the admin
      answering over HTTP.

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
- [x] 5.2 **An order placed starts an execution on the cluster, and it completes.** An observer on
      `sales_order_place_after` — the event Magento actually emits, the same from checkout, REST or
      admin — calls `startAsync()` and returns; starting the workflow in the request would kill it
      with the request, which is OST003's failure exactly. Order `000000001` produced
      `durable-order-000000001` on the cluster, the workers drove it to
      `'notify:charge:000000001'`, one charge.
      The observer never throws: a placed order stays placed. It lives in the bench, because which
      workflow starts on which order is a project's decision; the module ships the door.
      ⚠ The order is created through Magento's own order API rather than clicked through checkout —
      the *event* is the real one, the click is not simulated. And the grid now reads the business
      workflow type with a `completed` status, which corrects what this change said twice: the
      `DurableJournal`/`running` reservation belongs to the in-process path only.
- [x] 5.3 **The failure OST003 names is gone, measured end to end.** An execution started on the
      cluster, both workers killed with `kill -9` during the stock reservation, both restarted:
      the order completes — `'notify:charge:ORD-acceptation-…'` — and the card is charged
      **exactly once**.
      It needed two things, not one: an activity worker (`--role=activity`), because on Temporal an
      activity is a task somebody must take; and a way to start an execution **on the cluster**
      (`WorkflowClient::startAsync()`), because `MagentoRuntime::run()` executes in-process and its
      activities die with it whatever journal sits underneath. The first alone would have changed
      nothing.
      *Previously, and kept for the record:* Killed with `kill -9` mid-reservation and restarted under the same execution id,
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

- [x] 6.1 **DUR046** — the package name and the one thing it costs (Magento resolves a controller
      from the *module name*, not the autoloader); two backends refused by **Composer** rather than
      by code, and a first version of that refusal deleted; why nothing rides Magento's queue and
      why the workers are commands; the lock, shared, whose use case evaporated with the queue; and
      the three things this host integration moved into the core — including a **fatal** dependency
      of the core on the Symfony bundle that only a non-Symfony host could see.
      It also says what it does not claim: the package is unpublished, CI resolves but does not
      boot, and Adobe's distribution is untested.
- [ ] 6.2 The home page selector drops the `?` from `gplanchat/durable-magento`, and its state stops
      being `planned`. Through the canvas, not the generated file.
- [x] 6.3 **Both languages carry Magento.** `documentation/user/packages/` gains a
      `gplanchat/durable-magento` section — declaration by `di.xml`, the contract that is *not*
      declared, the two backends Composer enforces, the DSN that decides, workers as commands, and
      the note that executions start on the cluster and not in the request. The Backends page says
      why the SQL row does not exist on this host.
      ⚠ Each section opens with a **warning that the package is not on Packagist**: documenting a
      `composer require` that does not resolve would be the documentation telling a lie the rest of
      this change spent its time avoiding.
- [x] 6.4 **OST004's Magento row has left the "not built yet" table** — struck through in both
      tables, marked settled, pointing at DUR046 and naming what is still missing (publication, a
      CI job that boots). OST003 §Magento becomes *§Magento — built*, and carries the two findings
      worth taking to the next host: nothing rides the host's queue when the backend is a cluster,
      and a genuinely foreign host corrected the core three times, once fatally.
