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
| **0** | The host *is* a Symfony application. `durable-bundle` registers unchanged. Cost: a documentation page. | Shopware 6, API Platform, Sulu, PrestaShop 9 (partly), Ibexa |
| **1** | Foreign container, foreign queue. A package has to be written from the bootstrap up. | Laravel, Magento, WordPress/WooCommerce, Drupal |
| **2** | The host already owns a job abstraction. The integration **substitutes** a runtime under an existing model rather than introducing a second one. | Akeneo BatchBundle, `php-etl/pipeline` |

Tier 2 is the interesting one, and it is the tier this project has never written for.

---

## 2. Tier 0 — Symfony applications

| Target | Why it works today | Reservation |
|---|---|---|
| **Shopware 6** | Plugins *are* bundles; Symfony Messenger is already in the stack. Order, payment capture and ERP synchronisation are the same flows as Sylius. | Best return of the tier. An agency market that pays. |
| **API Platform** | It is a Symfony bundle itself. A `ProcessorInterface` can hand back a workflow handle and a `202 + Location` instead of blocking on the work. | Zero code. This is a documentation page, not a package. |
| **Sulu** | A plain Symfony application; the bundle registers unchanged. | Near-zero cost, near-zero volume. |
| **PrestaShop 9** | Back office is Symfony. | Front controllers are legacy. "Works with the bundle" is half true, and the half matters. |
| **Ibexa** | Symfony, enterprise, integration-heavy. | Low volume. |

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

## 4. Tier 2a — Akeneo, and the job model that is already there

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
Akeneo and `php-etl/pipeline`. **Whichever is written first pays for both**, which is an argument
for writing the smaller one first.

---

## 6. What this says about the roadmap

| Target | Tier | What it needs | Verdict |
|---|---|---|---|
| Shopware 6, API Platform, Sulu | 0 | Wiring and an admin view | **Planned.** Cheap — the bundle does the work — but announced as planned, not as working today (§2). |
| Akeneo | 2 | A `BatchBundle` bundle | **Planned.** Blocked on the checkpoint-granularity decision. |
| `php-etl/pipeline` | 2 | A durable step runner | **Strongest fit.** Shares §4's decision; internal product, so the feedback loop is short. |
| Laravel | 1 | Service provider, queue, migrations | **Planned**, and the positioning has to answer `durable-workflow/workflow` before the package exists. |
| Magento | 1 | Module, consumers | **Planned.** Bench already in the repository. |
| WooCommerce | 1 | Everything, on a hostile platform | Not now. Right product (DBAL), wrong moment. |
| Drupal | 1 | Module, queue | Not now. |
| PrestaShop 9, Ibexa | 0 | A documentation line | When somebody asks. |

**Non-goals.** Nothing here fixes an order of work. What the tiers buy is an estimate: three of
these targets are a week of wiring on a stack the bundle already fits, two are a bootstrap, and two
are the same design problem written twice. The homepage prints one word — *coming soon* — for all
of them, and that is deliberate; this table is where the difference lives.

---

## References

- [OST001 — Alternative durable execution backends](OST001-alternative-durable-execution-backends.md) §6 records the PHP competitive landscape.
- [DUR006 — No official Temporal PHP SDK or RoadRunner](../adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md)
- [DUR030 — DBAL backend: simplified durable execution on a single SQL database](../adr/DUR030-dbal-backend-simplified-durable-execution.md)
- [DUR037 — Run observation is a projection](../adr/DUR037-run-observation-as-a-projection.md) — the pattern the Akeneo `StepExecution` projection would follow.
