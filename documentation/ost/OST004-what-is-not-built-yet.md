# OST004 — What is not built yet: cost, and what blocks it

## Status

Exploration — no decision. A companion to
[OST003](OST003-php-ecosystem-integrations.md), which sorts *integrations* by cost. This document
covers the rest of the backlog — the engine, the ports, the functional gaps against the Temporal PHP
SDK, and the tooling — and adds one column OST003 deliberately left out: **what blocks each item**.

OST003 §6 states a non-goal — "nothing here fixes an order of work" — and that stands. A blocker is
not an order: it is a fact about which pairs of items cannot be swapped. Three such facts already
exist in the repository, written down in the changes themselves, and they are the useful part of any
estimate here.

---

## 1. The unit of estimate

This repository has never costed work in days, and inventing that precision now would read as
noise. Four registers, and the register is the estimate:

| Register | Magnitude | What it means | What makes it wrong |
|---|---|---|---|
| **Wiring** | **A week or under** | The shape exists and is being repeated. DI, a command, a projection, a set of rule configurations. | Nothing, usually. This is the register that behaves. |
| **A bootstrap** | **Weeks** | A package written from the container up: service wiring, queue, worker, migration. | The host's queue semantics, discovered late. That is what spreads the weeks, not the volume of code. |
| **A design question** | **Unknowable until the decision** | The cost is dominated by a decision nobody has taken. Any number quoted before the decision is quoted about the wrong thing. | Quoting it anyway. |
| **A probe** | **Unknowable until the server answers** | The cost cannot be known until a real server, or a real host, answers. The probe is the first task, not the preamble. | Designing against an assumption, then finding the assumption wrong at task 12. |

The magnitudes are OST003's, not new ones: its §6 already closes on "three of these targets are a
week of wiring on a stack the bundle already fits, two are a bootstrap, and two are the same design
problem written twice."

`nexus-handler-side` and `workflow-versioning` both open on a probe, and both say so in their first
section. That is the convention this table describes, not an addition to it.

---

## 2. In flight — the four open changes

Task counts are `openspec/changes/*/tasks.md` at the time of writing, and *at the time of writing*
is doing real work in that sentence: two lines of this table moved between the first draft of this
document and the day it landed. They are still the honest measure of remaining scope — estimating a
partly-landed change from its proposal overstates every one of them.

| Change | Done / total | Register | Blocks |
|---|---|---|---|
| `workflow-replay-divergence-guard` | **20 / 20** | Landed, on a design settled by [DUR042](../adr/DUR042-replay-divergence-guard.md) | — (it was `workflow-versioning`'s blocker) |
| `query-plumbing-leaves-the-environment` | 23 / 24 | Wiring — finished; its last task is blocked on tests it did not write | — |
| `workflow-versioning` | 0 / 18 | A probe, then wiring | The Rector set (§6), the comparison page's honesty (§4) |
| `nexus-handler-side` | 28 / 31 | Built. The probe reported, and the build followed it | — |

**The one remaining task on the query change has now been run against a live Temporal server**
(6.4), and it is red — for reasons that predate the change. Eight of thirteen tests error, in
exactly two causes and neither on the query registry: six construct a `TemporalStartingEventStore`
that exists nowhere in the repository, and two handed a `WorkflowNamespace` to something that wants
a string — those two are repaired. The counter stays at 23 / 24; the tick is not this change's to
take.

**The CI job that was supposed to be watching this reported success while testing nothing** —
thirteen tests, three assertions, ten skipped, in forty-five milliseconds. The cause was a port:
`symfony/.env` publishes the Temporal frontend on `7234` so it can sit beside a local server on
`7233`, and the job's DSN aimed at `7233`. Nothing answered, every server-touching test skipped, and
the job announced success. It is fixed, and the job now runs fourteen tests and **fails** on the one
remaining cause. A gate that cannot fail is not evidence; this one can. See the change's §7.

**The divergence guard is done, and its remainder was narrower than its proposal all along** —
which is the case this table exists to make. Activity slots (`2f0593e`), then Nexus and child
workflows (`7ef0efa`), then the cost measurement and the timer gap — where task 1.4 had found there
is no identity to compare, so the deliverable was a test documenting the gap rather than a
comparison. **`workflow-versioning` is therefore unblocked**, and its probe is taken.

**`nexus-handler-side` was the largest single piece of unbuilt work in the repository**, and its
probe made it larger rather than smaller: two independent timeout budgets, an envelope decision
(`{operationId, payload}` taught to handlers, or dropped in favour of the raw payload), and a
retryable-versus-terminal error classification the caller path never had to make. Section 1bis is
three design questions found by running the thing, which is the probe doing its job.

> **Since built** — the worker, both response shapes, cancellation, registration and the docs, at
> 28/31. Every one of those three questions was answered by measurement rather than argument, and
> one of them overturned the design's own wording: what correlates a deferred answer is the
> **callback attached to the fulfilling workflow**, not the token the handler returns. See
> [DUR045](../adr/DUR045-serving-a-nexus-operation.md).

---

## 3. The ports, now covered

[DUR041](../adr/DUR041-store-parity-is-a-suite-every-adapter-runs.md) turned the two-store parity
test into a suite any adapter runs, and it is now **accepted for all four ports**:
`EventStoreConformanceTestCase`, `EventStoreReplayConformanceTestCase`,
`WorkflowMetadataStoreConformanceTestCase`, `ChildWorkflowParentLinkStoreConformanceTestCase` and
`WorkflowRunCatalogConformanceTestCase`, each run by both the in-memory and the DBAL adapter.

An earlier draft of this section counted three ports still uncovered and called them the cheapest
item here with a downstream consequence. They were, and they were written while it was being
reviewed. **What that clears is the Laravel blocker**: OST003 §3 argued a fourth adapter family is
made safe by construction only once the suite exists for every port it must satisfy, and it now
does. A Laravel backend is a bootstrap with nothing in front of it.

---

## 4. The functional gaps against the SDK

[The comparison page](../user/comparison/) §8 names these publicly. They are commitments, not a
backlog someone else keeps.

| Gap | Register | Note |
|---|---|---|
| **Workflow versioning** (`Workflow::getVersion()`) | A probe, then wiring | The significant one. Second behind the divergence guard, because a version marker is the sanctioned exception to that guard, and an exception needs a rule to except. |
| **Nexus handler side** | **Built** — see [DUR045](../adr/DUR045-serving-a-nexus-operation.md) | §2. No other PHP implementation offers it, the SDK included. |
| **Saga helper** | Wiring, small | The capability exists — a deadline and a compensation path, written out in [Creating a workflow](../user/workflows/). What is missing is the sugar. |
| **`Workflow::now()`, `Workflow::uuid4()`** | Nothing to build | Both are `sideEffect()` on this side, recorded once and replayed. Not a gap; a different spelling. It matters in §6. |

---

## 5. Integrations — OST003, condensed

Restated here only so the single table in §7 is complete. The reasoning belongs to OST003.

| Target | Register | Blocked on |
|---|---|---|
| Shopware 6, Sulu | Wiring, plus a CI bench nobody has asked for yet | Somebody running one for real (OST003 §2) |
| API Platform state processor | Wiring — one class, two adapters | — |
| Akeneo `BatchBundle` | **A design question**: checkpoint granularity | The decision, not the code |
| `php-etl/pipeline` | The same design question, one level down | The same decision — whichever is written first pays for both |
| Laravel | A bootstrap, plus a fourth adapter family | — (§3's suites have landed) |
| Magento | A bootstrap. The `magento/` bench is now tracked — it was untracked until `change/magento-module` §1.1, so it existed on one machine and in no clone | — |
| WooCommerce, Drupal, PrestaShop 9, Ibexa | — | Not now (OST003 §6) |

---

## 6. `gplanchat/durable-rector` — a migration set for projects on the Temporal PHP SDK

### Why it belongs in this document rather than in a wiki page

A project already running the official SDK is the one population that has written durable workflows
in PHP, decided the trade-offs matter, and would have to rewrite every workflow class by hand to
evaluate this one. [The comparison page](../user/comparison/) enumerates every difference between
the two surfaces — that is what it is for. **A difference the comparison page can state in a table
is, for most rows, a difference a Rector rule can rewrite**, and for the rest it is a difference a
Rector rule can *report* precisely enough that a human knows what is left.

**The three buckets below are written.** `gplanchat/durable-rector` exists, ships
`config/sets/temporal-sdk.php`, and is covered by `tests/unit/DurableRector/`. What each bucket
turned out to cost, and where bucket 2's boundary was drawn, is recorded under each.

`gplanchat/durable-phpstan` is the precedent for the packaging: a first-party satellite,
`self.version` against the core, its own `type`, published by splitsh like the rest
([DUR020](../adr/DUR020-monorepo-splitsh-and-satellite-repositories.md)).

### The three buckets, and the split *is* the estimate

**Bucket 1 — configuration, not code.** Rector ships the rules; the set file supplies the map.

| Transformation | What does it |
|---|---|
| `#[WorkflowInterface]` on the interface → `#[Workflow(name: …)]` on the class | An attribute rename, **plus the synthesized name** — see the caveat below |
| `#[ActivityInterface]` → `#[Activity(name: …)]`, `#[ActivityMethod]` → `#[ActivityMethod(name: …)]` | The same rename, and the **same caveat, twice**: both attributes take a mandatory `name` |
| `#[SignalMethod]`, `#[QueryMethod]`, `#[UpdateMethod]`, `#[WorkflowMethod]` | A plain attribute rename — the vocabulary is deliberately close ([comparison §4](../user/comparison/)) |
| `Temporal\Exception\Failure\ActivityFailure` → `DurableActivityFailedException`, and the rest of the failure hierarchy | A class rename map |
| `Promise::all()` / `Promise::any()` → `$this->environment->all()` / `any()` | A static-method rename, plus the receiver change of bucket 2 |

Only the last two turned out to be configuration. The three attribute rows are **name synthesis**,
not renaming — see the caveat below — so they are two rules of the package's own,
`ActivityContractAttributesRector` and `WorkflowClassAttributesRector`. The failure map is
`RenameClassRector` (Rector's own; not `ClassRenameRector`, which is what memory offers and what the
first draft of this document said).

Register: **wiring**, and it was.

**Bucket 2 — type- and scope-aware transforms.** This is where the weeks are, and each of the three
below is its own rule with its own failure mode.

- **`yield` and `yield from` removal, and the return type that comes back.** Stripping the keyword is
  the easy half. The hard half is that the SDK method *could not* declare a useful return type — a
  generator is what it returns — so the type has to be inferred from what the body returns, and
  where inference fails, the rule must leave the method typeless and say so rather than guess. This
  rule is the whole reason a migration set is worth writing, and the whole reason it is not a
  weekend.
- **`Workflow::` → `$this->environment->`.** The receiver does not exist in the source class. The
  rule has to **add** a promoted `readonly WorkflowEnvironment $environment` constructor parameter,
  and reconcile with a constructor that is already there. Mechanical, but it edits a part of the
  class the call site never mentions.
- **Arguments that mostly do not change, and one that does.** There is no `Workflow::sleep()` — the
  SDK's timer is `Workflow::timer()`, and **`yield` is what tells the two apart**: `yield
  Workflow::timer(X)` waits, so it becomes `$this->environment->sleep(X)`; a bare `Workflow::timer(X)`
  handed to a race assembles, so it becomes `timer(X)`. The argument itself survives either way —
  Durable takes `Duration|\DateInterval|\DateTimeInterface|int|float`, and the samples' own
  `CarbonInterval` is a `\DateInterval`. Rewriting to `Duration::seconds(3600)` is idiomatic, not
  required, so it belongs in a second opt-in set. `Workflow::awaitWithTimeout($t, $c)` → `await($c,
  $t)` is a genuine reordering, and it is the only one.

Register: **a bootstrap** — and the estimate held for the reason it gave. The volume of code was
never the cost; the fixture corpus was, and two of the three shapes above were *changed* by what a
real corpus said rather than by what this section predicted.

**Where the boundary was drawn, deliberately.** The rule removes a `\Generator` return type; it
never writes one. Removing is mechanical and cannot be wrong — the method genuinely stopped being a
generator. Writing one would be a guess with a `TypeError` behind it, and the SDK gave it nothing to
guess from. An interface that declared `\Generator` loses it too, because a class may not widen what
its own contract narrowed. What a migrated method returns stays undeclared, and that is a smaller
promise than a wrong signature.

**And what the corpus changed.** The first draft rewrote every `yield` in every class. Run over
`temporalio/samples-php`, it turned an interceptor's plain iterator —
`ActivityAttributesInterceptor::iterateOptions()`, yielding reflection attributes — into
`await()` calls. `yield` belongs to PHP before it belongs to Temporal, and no amount of reading the
SDK would have surfaced that: a corpus did. The rule now qualifies a class first, by an implemented
`#[WorkflowInterface]` or a facade call somewhere in it, and touches nothing else.

**And the arity nobody would have found in a corpus.** Every `Workflow::await()` in `samples-php`
passes exactly one condition, so the corpus had nothing to say — but the SDK's signature is
`await(callable|Mutex|PromiseInterface ...$conditions)`, settling on the first, while Durable's
second parameter is a **deadline**. Two conditions rewritten as-is would become a condition and a
timeout: no error, no crash, a workflow waiting on the wrong thing. Reading the signature is what
catches that class, and reading a corpus is what catches the interceptor above. Neither substitutes
for the other.

**Its neighbour had to grow with it.** `Workflow::newActivityStub(C::class, ActivityOptions::new()
->withStartToCloseTimeout(…))` rewrites cleanly to `activityStub(C::class, ActivityOptions::new()
->…)` — and the result is code that *reads* migrated and cannot run, because Durable's
`ActivityOptions::of()` over `ActivityTimeouts` and `RetryLimit` is not that shape. The five options
classes are now reported by bucket 3, in the same pass that rewrites the call around them. Never
produce output that reads as done when it is not is the one invariant the whole package rests on.

**Bucket 3 — detect and report, transform nothing.** Cheapest to build, the highest value, and
**written**: `UnmigratableTemporalCallRector` comments every call the migration cannot make and
changes nothing else, so the answer to "is this migration available to us at all" arrives before
anybody rewrites a line.

**It is an allow-list, and that is the whole design.** `Workflow::` carries some forty static
methods; `WorkflowEnvironment` answers eight. A deny-list of known-bad names passes in silence every
method nobody enumerated — including the ones the next SDK release adds. So seven names are
recognised as *the execution-model half will rewrite this* — `newActivityStub`,
`newChildWorkflowStub`, `await`, `awaitWithTimeout`, `timer`, `sideEffect`, `continueAsNew` — and
everything else is reported, with a reason where there is one worth giving.

**Where bucket 3 lives, decided.** In the Rector set, not in `gplanchat/durable-phpstan`. A
detection with no transformation is a PHPStan rule wearing a Rector costume — but a team runs the
migration once, and the report is worth least on the day they have to install a second tool to read
it. One command wins over the tidier taxonomy. The cost is that the report *is* a transformation: a
comment. It carries a `durable-rector:` marker so a second pass does not stack a second copy, and
`git checkout` undoes it.

### What the official samples say about this bucket

Run over the 29 workflow classes of
[`temporalio/samples-php`](https://github.com/temporalio/samples-php) — a corpus Temporal maintains,
not one chosen here — the rule reports **23 findings in 10 files**. (The whole set touches 52 files
of that repository: 27 workflow classes, 23 activity contracts, 3 failure renames, 10 reports.)

| Reported | Times |
|---|---|
| `Workflow::async()`, `Workflow::asyncDetached()` | 4 |
| `Workflow::runLocked()`, `new Mutex` | 4 |
| `Workflow::getInfo()`, `Workflow::getCurrentContext()` | 4 |
| `Workflow::executeActivity()` (activity by name — [DUR039](../adr/DUR039-workflow-authoring-surface.md)) | 3 |
| `Workflow::allHandlersFinished()` | 2 |
| `new Saga` | 2 |
| `upsertSearchAttributes()`, `now()`, `newContinueAsNewStub()`, `isReplaying()` | 1 each |

**`Workflow::getVersion()` appears zero times**, and this document named it as the hard blocker. It
is still the one with no target at all, but it is not what a migration actually hits. What it hits
is the coroutine, mutex and introspection group — `async`, `runLocked`, `getInfo` — none of which
has a Durable counterpart, and none of which is a *rewrite*: a workflow built on `async()` and
`runLocked()` is a redesign, not a long migration. That is a better thing to learn from a corpus
than from a list of predictions, and it is why the rule was pointed at one.

### Two constraints that decide the shape

**`temporal/sdk` must never enter `composer.lock`.** The comparison page states publicly that it is
not there, and [DUR006](../adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md) is why.
Rector matches on fully-qualified name strings and does not autoload the classes it rewrites, so the
fixtures declare stub SDK attributes **under the SDK's own namespace**, in this repository's test
tree — the namespace has to be the real one for the rules to match, and only the shape they read is
reproduced. A `require-dev` on the SDK would be the
lazy route and it would falsify a published claim.

**The type name has to survive the attribute rewrite — and it is three attributes, not one.** The
SDK derives a workflow type from the `#[WorkflowInterface]`'s short name and an activity type from
the interface prefix plus the method's short name. Durable's `#[Workflow]`, `#[Activity]` and
`#[ActivityMethod]` all take `name` as a **mandatory** constructor argument, so the rename cannot
even produce code that runs without inventing one — and a rule that invents it from the class it
happens to be sitting on, rather than from the interface the SDK derived it from, produces a class
that compiles, passes its tests, and **silently fails to resolve every run already in flight**. The
activity side is the easier one to get wrong, because the name it must reconstruct is a
concatenation of two sources rather than one short name.

These are the rules in the set whose bug is invisible until production, and they are the ones that
get the integration fixture.

**The corpus says how often it would have fired.** Over `temporalio/samples-php`, the workflow rule
writes 27 `#[Workflow(name: …)]`, and **24 of them carry a name the class's short name would not
have produced** — `'SimpleActivity.greet'` on a class called `GreetingWorkflow`, `'Saga.Compensate'`,
`'MoneyTransfer'`, `'Zonk.start'`. Only three interfaces leave the type to the fallback. On
Temporal's own samples, a migration that dropped the name would have silently renamed **nine
workflow types out of ten** — which is the hazard, measured rather than argued.

### What the set does not promise

Not a push-button migration. Over `temporalio/samples-php` the set changes **58 files** — 27
workflow classes renamed and re-attributed, 23 activity contracts, 33 bodies moved off the facade,
27 files carrying a report — and every one of those files still needs reading, because what the set
cannot express it comments rather than converts. The deliverable is the mechanical majority, plus a report naming
precisely what a human must decide — which is a smaller promise than "migrate your project", and the
only one the buckets support.

| Item | Register | Blocked on |
|---|---|---|
| Bucket 1 — attributes and the failure map | Wiring | **Done** — `gplanchat/durable-rector` |
| Bucket 2 — colour removal, receiver injection | A bootstrap | **Done** — return types removed, never synthesised |
| Bucket 3 — the report | Wiring | **Done** — in the Rector set; the `getVersion()` line still says "you cannot" until **`workflow-versioning`** lands |

---

## 7. Everything, in one table

Register, then blocker. Neither column fixes an order; together they say which swaps are not
available.

| Item | Register | Blocked on |
|---|---|---|
| `query-plumbing-leaves-the-environment` | Wiring — 23/24 | A live server for the last task |
| ~~`workflow-replay-divergence-guard`~~ | Wiring — 20/20 | **Done** |
| ~~Conformance suites, the three remaining store ports~~ | Wiring | **Done** — DUR041 covers all four |
| Saga helper | Wiring, small | — |
| ~~Rector bucket 1~~ | Wiring | **Done** |
| API Platform state processor | Wiring | — |
| Shopware 6, Sulu | Wiring | A real user |
| ~~Rector bucket 3~~ | Wiring | **Done** |
| `workflow-versioning` | A probe, then wiring | — (the guard has landed; the probe is taken) |
| ~~Rector bucket 2~~ | A bootstrap | **Done** |
| Magento | A bootstrap | — |
| Laravel | A bootstrap + a fourth adapter family | — |
| `nexus-handler-side` | **Built** — 28/31; the probe grew it, then answered it | Settled; see DUR045 |
| Akeneo `BatchBundle` | **A design question** | Checkpoint granularity |
| `php-etl/pipeline` | **A design question** | The same one |

**The four orderings, and they are all that this document fixes:** versioning after the divergence
guard; a Laravel backend after the conformance suites; the Rector set complete only after versioning;
Akeneo and `php-etl/pipeline` after one shared decision, in either order, once.

**Two of the four were discharged while this document was in review** — the guard landed and the
three remaining conformance suites were written — so versioning and a Laravel backend now have
nothing in front of them. That is the argument for stating blockers rather than an order: an
ordering goes stale silently, a blocker goes stale by being *met*, and the difference is visible.

---

## References

- [OST003 — PHP ecosystem integrations](OST003-php-ecosystem-integrations.md) — §5 here is its §6, condensed.
- [Durable and the Temporal PHP SDK](../user/comparison/) — the difference table §6 mechanizes.
- [DUR006 — No official Temporal PHP SDK or RoadRunner](../adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md)
- [DUR020 — Monorepo, splitsh, and satellite repositories](../adr/DUR020-monorepo-splitsh-and-satellite-repositories.md) — how `durable-rector` would be published.
- [DUR039 — The workflow authoring surface](../adr/DUR039-workflow-authoring-surface.md) — why bucket 3 has an entry the SDK has no equivalent for.
- [DUR041 — Store parity is a suite every adapter runs](../adr/DUR041-store-parity-is-a-suite-every-adapter-runs.md) — §3.
- [DUR042 — The replay divergence guard](../adr/DUR042-replay-divergence-guard.md)
