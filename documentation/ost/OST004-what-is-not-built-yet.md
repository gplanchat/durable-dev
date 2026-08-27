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

Task counts are `openspec/changes/*/tasks.md` at the time of writing. They are the honest measure of
remaining scope: three of these four are **partly landed**, and estimating them from their proposals
would overstate every one.

| Change | Done / total | Register | Blocks |
|---|---|---|---|
| `query-plumbing-leaves-the-environment` | 23 / 24 | Wiring — finished bar one task | — |
| `workflow-replay-divergence-guard` | 11 / 20 | Wiring, on a design already settled by [DUR042](../adr/DUR042-replay-divergence-guard.md) | `workflow-versioning` |
| `workflow-versioning` | 0 / 18 | A probe, then wiring | The Rector set (§6), the comparison page's honesty (§4) |
| `nexus-handler-side` | 7 / 32 | A probe that already reported, then a bootstrap | — |

**The one remaining task on the query change needs a live Temporal server** (6.4). It is not
unfinished work; it is work that cannot run where the rest of the suite runs. Same for 4.2 of the
divergence guard.

**The divergence guard's remainder is narrower than its proposal.** Activity slots (`2f0593e`),
Nexus and child workflows (`7ef0efa`) have landed. What remains is timers — where task 1.4 found
there is no identity to compare, so the deliverable is a test that documents the gap rather than a
comparison — the failure's legibility, and the documentation. That is the cheapest of the four.

**`nexus-handler-side` is the largest single piece of unbuilt work in the repository**, and its
probe made it larger rather than smaller: two independent timeout budgets, an envelope decision
(`{operationId, payload}` taught to handlers, or dropped in favour of the raw payload), and a
retryable-versus-terminal error classification the caller path never had to make. Section 1bis is
three design questions found by running the thing, which is the probe doing its job.

---

## 3. The ports that are one-quarter covered

[DUR041](../adr/DUR041-store-parity-is-a-suite-every-adapter-runs.md) turned the two-store parity
test into a suite any adapter runs. `EventStoreConformanceTestCase` and
`EventStoreReplayConformanceTestCase` exist, and both the in-memory and DBAL adapters run them.

**Three ports have no suite:** `WorkflowMetadataStore`, `ChildWorkflowParentLinkStoreInterface`,
`WorkflowRunCatalogInterface`.

| Item | Register | Blocks |
|---|---|---|
| Conformance suites for the three remaining store ports | Wiring — the pattern is written, three times over | A Laravel backend (OST003 §3), any fourth adapter family |

This is the cheapest item in this document with a downstream consequence, and OST003 already argues
it is worth doing whether or not the thing it unblocks is ever written.

---

## 4. The functional gaps against the SDK

[The comparison page](../user/comparison/) §8 names these publicly. They are commitments, not a
backlog someone else keeps.

| Gap | Register | Note |
|---|---|---|
| **Workflow versioning** (`Workflow::getVersion()`) | A probe, then wiring | The significant one. Second behind the divergence guard, because a version marker is the sanctioned exception to that guard, and an exception needs a rule to except. |
| **Nexus handler side** | A bootstrap | §2. No PHP implementation offers it today, the SDK included. |
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
| Laravel | A bootstrap, plus a fourth adapter family | §3's three conformance suites |
| Magento | A bootstrap; bench already in `magento/` | — |
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

The skeleton is already on disk — `src/DurableRector/config/sets/` and
`src/DurableRector/tests/Rector/Fixture/` — and contains no file. This section is what would go in
them.

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

Rector ships configurable rules for all five shapes; **which ones, by name, gets pinned when the set
file is written** against the version in `composer.json` rather than quoted from memory here.

Register: **wiring**. A day of map-writing and a fixture per row.

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
- **Argument shapes that mostly do not change.** `Workflow::sleep(3600)` becomes
  `$this->environment->sleep(3600)` and stops there: `sleep()` accepts `int|float` as well as
  `Duration`. Rewriting to `Duration::seconds(3600)` is idiomatic, not required — so it belongs in a
  second, opt-in set, not in the one a migration runs first. `Workflow::awaitWithTimeout($t, $c)`
  → `await($c, $t)` is a genuine reordering, and it is the only one.

Register: **a bootstrap.** Not because of volume, but because a rule that rewrites return types
needs a fixture corpus before it needs features.

**Bucket 3 — detect and report, transform nothing.** Cheapest to build, arguably the highest value,
because it is the bucket that tells a team whether the migration is even available to them.

| Detected | Why nothing is rewritten |
|---|---|
| `Workflow::getVersion()` | No target exists (§4). Until `workflow-versioning` lands, a run using it cannot migrate at all. |
| `Workflow::newUntypedActivityStub()`, activity-by-name calls | Removed on purpose ([DUR039](../adr/DUR039-workflow-authoring-surface.md)): the typed stub is the only way. The replacement is a contract interface a rule cannot invent. |
| `Workflow::` reached from a static helper, or from a class with no constructor to inject into | There is no `$this` to route through. |
| `Workflow::now()`, `Workflow::uuid4()` | `sideEffect()` is the equivalent and the rewrite is mechanical — but it changes *when* the value is captured, which is a review a human owes the code. |
| Saga helper, Nexus handler | §4. |

**Whether bucket 3 is a Rector set at all** is worth one line: `gplanchat/durable-phpstan` already
exists to report on code without changing it, and a detection with no transformation is a PHPStan
rule wearing a Rector costume. The counter-argument is that a team runs the migration set once and
wants one command; splitting the report across two tools they must both install is a worse first
five minutes. Undecided, and cheap either way.

### Two constraints that decide the shape

**`temporal/sdk` must never enter `composer.lock`.** The comparison page states publicly that it is
not there, and [DUR006](../adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md) is why.
Rector matches on fully-qualified name strings and does not autoload the classes it rewrites, so the
fixtures declare stub SDK classes in the test namespace. A `require-dev` on the SDK would be the
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

### What the set does not promise

Not a push-button migration. The deliverable is the mechanical majority, plus a report naming
precisely what a human must decide — which is a smaller promise than "migrate your project", and the
only one the buckets support.

| Item | Register | Blocked on |
|---|---|---|
| Bucket 1 — the rename map | Wiring | — |
| Bucket 2 — colour removal, receiver injection | A bootstrap | Nothing technical; a fixture corpus first |
| Bucket 3 — the report | Wiring | Its home (Rector or PHPStan), and **`workflow-versioning`** for the `getVersion()` row to say anything but "you cannot" |

---

## 7. Everything, in one table

Register, then blocker. Neither column fixes an order; together they say which swaps are not
available.

| Item | Register | Blocked on |
|---|---|---|
| `query-plumbing-leaves-the-environment` | Wiring — 23/24 | A live server for the last task |
| `workflow-replay-divergence-guard` | Wiring — 11/20 | — |
| Conformance suites, three remaining store ports | Wiring | — |
| Saga helper | Wiring, small | — |
| Rector bucket 1 | Wiring | — |
| API Platform state processor | Wiring | — |
| Shopware 6, Sulu | Wiring | A real user |
| Rector bucket 3 | Wiring | Its home; `workflow-versioning` for one row |
| `workflow-versioning` | A probe, then wiring | `workflow-replay-divergence-guard` |
| Rector bucket 2 | A bootstrap | A fixture corpus |
| Magento | A bootstrap | — |
| Laravel | A bootstrap + a fourth adapter family | The three conformance suites |
| `nexus-handler-side` | A bootstrap — 7/32, and the probe grew it | Three decisions from §1bis |
| Akeneo `BatchBundle` | **A design question** | Checkpoint granularity |
| `php-etl/pipeline` | **A design question** | The same one |

**The four orderings, and they are all that this document fixes:** versioning after the divergence
guard; a Laravel backend after the conformance suites; the Rector set complete only after versioning;
Akeneo and `php-etl/pipeline` after one shared decision, in either order, once.

---

## References

- [OST003 — PHP ecosystem integrations](OST003-php-ecosystem-integrations.md) — §5 here is its §6, condensed.
- [Durable and the Temporal PHP SDK](../user/comparison/) — the difference table §6 mechanizes.
- [DUR006 — No official Temporal PHP SDK or RoadRunner](../adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md)
- [DUR020 — Monorepo, splitsh, and satellite repositories](../adr/DUR020-monorepo-splitsh-and-satellite-repositories.md) — how `durable-rector` would be published.
- [DUR039 — The workflow authoring surface](../adr/DUR039-workflow-authoring-surface.md) — why bucket 3 has an entry the SDK has no equivalent for.
- [DUR041 — Store parity is a suite every adapter runs](../adr/DUR041-store-parity-is-a-suite-every-adapter-runs.md) — §3.
- [DUR042 — The replay divergence guard](../adr/DUR042-replay-divergence-guard.md)
