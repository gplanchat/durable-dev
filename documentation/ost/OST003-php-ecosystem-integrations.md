# OST003 — PHP ecosystem integrations: where Durable is worth wiring

## Status

Exploration — no decision. Feeds the roadmap published on the homepage
(`hugo-docs/layouts/index.html`); an integration that lands should leave an ADR behind.

## Opportunity

Two integrations exist today: `gplanchat/durable-bundle` (Symfony) and `gplanchat/durable-plugin`
(Sylius). The homepage names Laravel and Magento as planned. The question this document answers is
not "which PHP communities are large" — it is **which ones trigger which cost**, because the two
are uncorrelated and only the second is actionable.

---

## 1. The axis that decides

An integration costs two things, and they behave differently:

- **Wiring** — DI, queue transport, worker command, an admin view. Paid once.
- **A CI bench** — a Docker stack, a pinned `composer.lock`, an image whose PHP version is not the
  host's. See `sylius/` and `magento/`. Paid **on every upstream release, forever.**

Sorting candidates by community size gets this backwards. Sorted by cost, they fall into three
tiers, and the tier — not the popularity — is what decides the order of work.

| Tier | What it means | Members |
|---|---|---|
| **0** | The host *is* a Symfony application. `durable-bundle` registers unchanged. Cost: wiring and an admin view. | Shopware 6, Sulu, PrestaShop 9 (partly), Ibexa |
| **1** | Foreign container, foreign queue. A package has to be written from the bootstrap up. | Laravel, Magento, WordPress/WooCommerce, Drupal |
| **2** | The host already owns a job abstraction. The integration **substitutes** a runtime under an existing model rather than introducing a second one. | Akeneo `BatchBundle`, Pimcore Generic Execution Engine, `php-etl/pipeline` |
| **∅** | Not a host at all — a contract two hosts implement. Belongs to no tier and cuts across two. | API Platform (§3) |

Tier 2 is the interesting one, and it is the tier this project has never written for. The row
with no number is the one that changes another line of this document — see §3.

---

## 2. Tier 0 — Symfony applications

| Target | Why it works today | Reservation |
|---|---|---|
| **Shopware 6** | Plugins *are* bundles; Symfony Messenger is already in the stack. Order, payment capture and ERP synchronisation are the same flows as Sylius. | Best return of the tier. An agency market that pays. |
| **Sulu** | A plain Symfony application; the bundle registers unchanged. | Near-zero cost, near-zero volume. |
| **PrestaShop 9** | Back office is Symfony. | Front controllers are legacy. "Works with the bundle" is half true, and the half matters. |
| **Ibexa** | Symfony, enterprise, integration-heavy. | Low volume. |

**API Platform is not in this table**, although it runs on Symfony. It is not an application; it
is a contract, and the same contract runs on Laravel. §3 is where it belongs, because that is
the argument it changes.

**The discipline that keeps this tier cheap:** none of these earns a CI bench until somebody runs
one for real. A bench added speculatively is a maintenance bill with no user behind it.

**How they are announced, and why it is not what the tier says.** The tier is a statement about
**cost**: a Tier 0 integration is wiring and an admin view, never a runtime. It is not a statement
about readiness, and the homepage announces all three as **coming soon** alongside Laravel and
Magento.

The reason is that the alternative was worse. "Installs and runs, not covered by our CI" reads as
an invitation, and an invitation on the front page is a `composer require` the project then has to
support — on a stack it has never booted. `durable-bundle` resolving against Shopware's pinned
Symfony (`^6.4 || ^7.0 || ^8.0` against `~7.4`) says the constraint solver agrees; it says nothing
about the plugin loader, the container compilation, or the Messenger transport under Shopware's own
configuration. Announcing a promise the project intends to keep costs less than an instruction it
cannot answer for.

---

## 3. Tier 1 — a package to write

### API Platform — one processor, two frameworks

`api-platform/state` ships **one** `ApiPlatform\State\ProcessorInterface`, and `api-platform/laravel`
(Laravel 11 and 12 since 4.2, Laravel 13 since 4.3) implements it against Eloquent the same way the
Symfony package implements it against Doctrine. A processor that starts a workflow and returns a
handle with `202 + Location` — instead of holding the request open for work that takes minutes — is
therefore **the same class on both frameworks**.

What is *not* the same is everything under it. The processor is portable; the container that builds
it, the queue that carries the activity, and the worker that drains it are not. So this is not a
free Laravel integration — it is a shared front end over two different backs, and the Laravel back
is exactly the Tier 1 package below.

**But it changes what that package is for.** "A durable engine for Laravel" is taken (below). "An
API Platform operation that survives the request, written once and deployed on either framework" is
not taken by anybody, and it is a smaller promise to keep: one interface, two adapters, no claim
about Laravel's queue semantics in general.

It is also the only target in this document whose value **grows** with a second integration rather
than being duplicated by it. Everything else in §2 and §3 is a per-host cost. This one is written
once and collected twice.

### Laravel — the square is occupied

`durable-workflow/workflow` (formerly `laravel-workflow/laravel-workflow`) is durable execution on
Laravel queues: `yield` as the checkpoint, own storage, no server, explicitly inspired by Temporal
and Azure Durable Functions, 1 000+ stars. `keepsuit/laravel-temporal` covers the other route —
official SDK, therefore RoadRunner, therefore out of scope under **DUR006**. Both are already
recorded in [OST001 §6](OST001-alternative-durable-execution-backends.md).

Two consequences, and neither is "don't go":

1. **Positioning.** Durable's Laravel entry cannot be "a durable engine for Laravel" — that product
   exists and is good at it. The entry is the **backend choice**: the same workflow code against a
   Temporal cluster *or* against one SQL database (DUR030), and a mixed Symfony / Sylius / Akeneo
   estate sharing a single engine with the Laravel application.
2. **Naming.** "Durable" against "Durable Workflow" will be read as the same project by anyone
   skimming Packagist. The Laravel package has to lead with `gplanchat/`, and the documentation has
   to name the other one rather than hope nobody notices.

### The Laravel backend: a fourth adapter behind the ports that already exist

The port is exactly the two substitutions it looks like — Messenger out, Laravel's queue in;
Doctrine out, Laravel's database layer in. And the seam it hangs on is **already cut**.

Four interfaces make up the storage side of the hexagon, and three families already sit behind them
with their own constraints:

| Port | In-memory | Temporal | DBAL |
|---|---|---|---|
| `EventStoreInterface` | `InMemoryEventStore` | `TemporalJournalEventStore`, `TemporalReadThroughEventStore` | `DbalEventStore` |
| `WorkflowMetadataStore` | `InMemoryWorkflowMetadataStore` | — | `DbalWorkflowMetadataStore` |
| `ChildWorkflowParentLinkStoreInterface` | `InMemoryChildWorkflowParentLinkStore` | — | `DbalChildWorkflowParentLinkStore` |
| `WorkflowRunCatalogInterface` | — | yes | `DbalWorkflowRunCatalog` |

Temporal already answers `EventStoreInterface` **twice**, and the DBAL side decorates its own
implementations with `ProjectingEventStore` and `ProjectingWorkflowMetadataStore`. Multiple adapters
per backend, each with its own constraints, is not a departure here — it is what the code already
does.

So a Laravel backend is **a fourth family behind the same four ports**, free to be written the way
Laravel makes cheap: `DB::table()`, a JSON column, a published migration. Not a connection
abstraction underneath one shared set of stores — that would put a seam *below* the hexagon's
boundary, and hand two adapters one shared query strategy and one shared dialect assumption, which
are precisely the things an adapter is supposed to own.

**Eloquent or the query builder is then an adapter's business, not the engine's.** Worth noting all
the same, because it is the one place the choice has a consequence: journal rows are append-only
facts — `execution_id`, `event_type`, a JSON `payload`, `recorded_at`. `DB::table()` writes them
directly; an ActiveRecord model adds identity, events, casts and timestamps over a row whose entire
contract is that it is never mutated. The adapter is free either way, and one of the two is less
work.

**What stops the journal from diverging is conformance, not shared code.** That objection —
"two implementations of the journal shape is two places to drift" — is a testing argument dressed as
an architecture argument, and the guard already exists: `DbalBackendParityTest` runs a real workflow
with an activity, a timer and a side effect against `InMemoryEventStore` and `DbalEventStore`, then
compares what replay reads from each. A payload deformed by a SQL round trip breaks replay silently,
not at write time, and that test is what catches it.

**It is written for two named stores, and that is the gap.** Promoting it into a reusable
conformance suite that every `EventStoreInterface` implementation runs — Temporal's two included —
is the work that makes a fourth adapter safe by construction. **That is the prerequisite for a
Laravel backend, and it is worth doing whether or not one is ever written.** It is now
[DUR041](../adr/DUR041-store-parity-is-a-suite-every-adapter-runs.md), and it is written: the four
store ports each have a conformance suite that the SQL and in-memory adapters run. A Laravel
adapter now starts by extending four classes.

**One constraint no adapter may drop, and Laravel's satisfies it for free.** DUR030 sells durable
execution on one database with no cluster, and that only pays if the journal append and the business
write land in one transaction — otherwise the activity writes, the process dies before the journal
records that it did, and replay runs it twice. A native Laravel adapter is on `DB::connection()` by
construction, so `DB::transaction()` closes over both. Reaching the same guarantee by handing
Doctrine DBAL the PDO out of `DB::connection()->getPdo()` is possible, and it is a workaround where
the adapter is the plain answer.

**Where the port is genuinely hard, and it is not storage.** `SingleResumeLockMiddleware` is a
Symfony Messenger middleware, and `DbalEventStore`'s own docblock leans on it: without it, two
workers replay the same execution and duplicate its commands. On Laravel that becomes
`WithoutOverlapping` or an atomic `Cache::lock()`, and no storage choice supplies it. It is also why
that middleware — and the hard requirement on `symfony/lock` and `symfony/messenger` in
`durable-bridge-dbal`'s `composer.json` — belongs outside the bridge: a Laravel application should
not install two Symfony components to get a SQL journal.

### Magento

Cron plus `MessageQueue` consumers, and the failure every integrator has seen is a consumer that
dies half way through an order. Conservative community, enterprise budgets, slow adoption — and
the bench is already in this repository (`magento/`).

### WooCommerce / WordPress

Largest PHP install base by a wide margin, and the **DBAL backend is genuinely the right product
for it**: one SQL database, no cluster, nothing to sell the client's host on. Against it: Action
Scheduler is already installed everywhere and already does the 80 % case, PHP 7 is still in the
field, there is no container and little Composer culture. High reach, low adherence.

### Drupal

A Symfony container, but modules declare services through their own mechanism. Cost close to
Laravel, payoff below WordPress. Below WooCommerce in priority, not above it.

---

## 4. Tier 2a — Akeneo and Pimcore, and the job models already there

Two PIM-shaped hosts, the same story twice: a job model that records what failed and cannot resume
it. Neither needs a second runtime — both need theirs to survive a step.

### Akeneo

Akeneo's `BatchBundle` has `JobInstance` / `JobExecution` / `StepExecution`, persisted status, and
an admin screen that shows them. What it does **not** have is a resume. A job that dies mid-step is
re-run whole; a stuck step is reset by hand from the administration UI — "Step never started" and
"Step timed out" are documented support procedures, not edge cases. On a catalogue import of any
size, "re-run it from the top" is a several-hour answer to a one-row problem.

That gap is exactly what durable execution closes, and the shape of the integration follows from
it:

- **Not a parallel runtime.** A `Job` / `Step` implementation whose execution *is* a durable
  workflow. `JobExecution` stays the thing the admin displays; the workflow owns the restart.
- **The bundle's real job** is keeping the two consistent — projecting workflow progress onto
  `StepExecution` — not replacing either.

**The open question, and it is the whole design:** `BatchBundle`'s item step is per-item
(reader → processor → writer). A durable journal entry per item on a million-row import is a
journal nobody wants to store or replay. **Checkpoint granularity — how many items per durable
step — is the decision to settle before any code is written.**

### Pimcore — the retry that was switched off on purpose

Pimcore 12 is a Symfony 7.4 application with `symfony/messenger` already in the stack, so Tier 0 is
free and beside the point. What matters is that it owns a job model of its own: the **Generic
Execution Engine**, where a `Job` carries named `steps` and a `JobRun` carries the execution.

Three things Pimcore documents about that engine make the argument better than we could:

- `stop_on_first_error` halts the job at the step that failed;
- **`max_retries: 0`**, configured that way *"to prevent data corruption"*;
- a single step cannot be cancelled — only the whole run.

*We turned retries off because replaying a step corrupts data* is a precise statement of the
problem durable execution solves. A journal makes a step safe to replay, because what already
happened is **read back** rather than done again. Pimcore did not decline retries out of caution; it
declined them because nothing underneath made them safe.

The shape is Akeneo's, one host over: a `Job` whose steps *are* durable steps, `JobRun` staying what
the admin displays, and the integration keeping the two consistent rather than replacing either.

---

## 5. Tier 2b — Gyroscops and `php-etl/pipeline`

`php-etl/pipeline` is an ETL pipeline built on generators: `extract()`, `transform()`, `load()`,
composed fluently, each step a coroutine the runner drives; rejection handling and a
`StateInterface` with `initialize()` / `teardown()`. It is a **single pass**. There is no
checkpoint and no resume: a pipeline that fails at row 400 000 restarts at row 1, or is made
restartable by hand, per pipeline, by whoever wrote it.

**This is the strongest technical fit in this document**, for a reason that has nothing to do with
market size: the pipeline is *already* a coroutine chain, and Durable's interpreter is *already* a
fiber driver over a journal. The two agree on what a step is. Mapping a pipeline step to a durable
step, a loader write to an activity, and the item cursor to journal state is not an adaptation of
one model to another — it is the same model with persistence added.

For Gyroscops that is a product difference, not a feature: a pipeline that survives the process, a
deployment, and the row that poisons it. **Resumable** and **re-runnable** are not the same offer.

**The cost, stated honestly:** the granularity problem of §4 is the same problem here, one level
down — a pipeline yields per item, and a journal per item is the wrong unit. Batching policy,
checkpoint interval, and what a rejection does to the journal are one design question shared by
Akeneo, Pimcore and `php-etl/pipeline`. **Whichever is written first pays for all three**, which is
an argument for writing the smallest one first — and the count is what makes that argument worth
acting on rather than noting.

---

## 6. What this says about the roadmap

| Target | Tier | What it needs | Verdict |
|---|---|---|---|
| Shopware 6, Sulu | 0 | Wiring and an admin view | **Planned.** Cheap — the bundle does the work — but announced as planned, not as working today (§2). |
| API Platform | — | One state processor, two adapters | **Planned**, and the one to write before Laravel: it is the same class on both frameworks, and it gives the Laravel package a promise nobody else is making (§3). |
| Akeneo | 2 | A `BatchBundle` bundle | **Planned.** Blocked on the checkpoint-granularity decision. |
| Pimcore | 2 | A bundle under the Generic Execution Engine | **Planned.** Same decision, same blocker — and its own documentation makes the case (§4). |
| `php-etl/pipeline` | 2 | A durable step runner | **Strongest fit.** Shares §4's decision; internal product, so the feedback loop is short. |
| Laravel | 1 | Service provider, resume lock, a fourth adapter family, migration | **Planned**, and **blocked on a conformance suite** for the four store ports (§3) — not on the adapter. The positioning has to answer `durable-workflow/workflow` first, and API Platform is the cheapest answer available. |
| Magento | 1 | Module, consumers | **Planned.** Bench already in the repository. |
| WooCommerce | 1 | Everything, on a hostile platform | Not now. Right product (DBAL), wrong moment. |
| Drupal | 1 | Module, queue | Not now. |
| PrestaShop 9, Ibexa | 0 | A documentation line | When somebody asks. |

**Non-goals.** Nothing here fixes an order of work. What the tiers buy is an estimate: three of
these targets are a week of wiring on a stack the bundle already fits, two are a bootstrap, and two
are the same design problem written twice. The homepage prints one word — *coming soon* — for all
of them, and that is deliberate; this table is where the difference lives.

### The rule this table earns: a mark follows a decision, it does not make one

**An ecosystem gets a logo and a chip once it has a row above — not before.**

This is written down because the reverse has now happened twice. Five ecosystems arrived as SVG
assets in one commit (Aimeos, Bagisto, Filament, Pimcore, Statamic), and TYPO3 after them, each
through the design canvas, none of them argued for anywhere. Nobody did anything wrong: a canvas is
where a layout gets tried, and trying a row of chips means drawing chips.

But a mark in the wizard is a **public claim** — it tells a reader we intend to integrate with that
project. When the claim ships before the argument, the argument has to be reconstructed afterwards
against an asset that is already in the repository, which is a bad order to think in.

Pimcore is the case that makes it concrete rather than theoretical. It **was** worth adding — and it
was filed in the wrong tier until somebody actually read its documentation. Tier 0 on the first
reading ("another Symfony application"), Tier 2 once the Generic Execution Engine turned up. The
mark had been in the repository for a day by then, and the correction cost an issue and a pull
request. A row in this table costs a paragraph.

**What the rule is not:** it is not a veto on the canvas. Sketching an ecosystem there is exactly
what a canvas is for. The rule is only that the sketch does not ship — no asset committed, no chip
on the page — until the row exists. And it does not apply retroactively to the marks that predate
it; those are now here, and the four without a row are the backlog this rule exists to stop growing.

**If it needs teeth**, the check is mechanical: every `hugo-docs/assets/logos/*.svg` should name a
target in the table above. Seven do today — Shopware, Sulu, API Platform, Akeneo, Pimcore, Laravel,
Magento. Six legitimately never will, and they make a short, stable exception list: the language and
the two backends (`php`, `doctrine`, `temporal`), the two integrations that already shipped
(`symfony`, `sylius`), and `illuminate`, which names a layer rather than a target. That leaves
exactly the four this rule is about. Worth writing the day the rule is first forgotten, not before.

---

## References

- [OST001 — Alternative durable execution backends](OST001-alternative-durable-execution-backends.md) §6 records the PHP competitive landscape.
- [DUR006 — No official Temporal PHP SDK or RoadRunner](../adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md)
- [DUR030 — DBAL backend: simplified durable execution on a single SQL database](../adr/DUR030-dbal-backend-simplified-durable-execution.md)
- Pimcore's Generic Execution Engine — [Jobs](https://docs.pimcore.com/platform/Pimcore/Development_Tools_and_Details/Generic_Execution_Engine/Jobs_and_Jobruns/Jobs/) and [Configuration](https://docs.pimcore.com/platform/next/Pimcore/Development_Tools_and_Details/Generic_Execution_Engine/Configuration/), where `max_retries: 0` and the reason given for it are stated.
- [DUR037 — Run observation is a projection](../adr/DUR037-run-observation-as-a-projection.md) — the pattern the Akeneo `StepExecution` projection would follow.
