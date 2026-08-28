# Design

## What was probed, and what was not

The house rule is to probe before encoding an invariant. There is no Temporal server in front of
this change, so the things worth probing are in this repository and in Laravel's own runtime. What
follows separates the two honestly, because the second list is what the bench in §1 of `tasks.md`
exists to answer.

**Measured, in this repository, before writing a line of the package:**

| Claim | How it was checked | Result |
|---|---|---|
| The Illuminate bridge has no queue and no jobs | `find src/Bridge/Illuminate -type f` | Eleven files: four stores, a schema, a migration, `Queue\ResumeLock`, a provider, a composer manifest, a README, a licence. No job, no transport. |
| `DurableIlluminateServiceProvider` binds nothing | Read | It registers migration paths and nothing else. Every port is unbound. |
| The bundle cannot select this backend | `grep illuminate src/DurableBundle/DependencyInjection/Configuration.php` | No hit. `event_store.type` has no `illuminate` value, and the Backends page is organised around that enum. |
| The Temporal bridge drags Symfony in | `grep -rho 'Symfony\\Component\\[A-Za-z]*' src/Bridge/Temporal` | Messenger ×11, DependencyInjection ×6, HttpKernel ×1, Config ×1, across seven files: `TemporalBridgeBundle`, the DI extension, `Resources/config/services.php`, and four Messenger transports. |
| `ResumeLock` waits without a Laravel application | Its own test suite runs with no application booted | It does — that is why it hand-rolls the wait instead of calling `Lock::block()`, which needs a global `now()`. |

**Assumed, and to be probed by the bench:**

1. ~~**What a queued job that waits on the lock costs.**~~ **Measured — see `tasks.md` §1.2, and
   it produced a decision rather than a preference.** Blocking holds 15 worker-seconds for 4
   seconds of work; releasing holds 4.2 and pays 19 s of wall clock for it. Neither is the answer,
   because both shapes carry a defect the numbers made visible: `release()` **consumes an attempt**
   (15 of 20 jobs failed at `--tries=5` having never run), and `around()`'s `waitSeconds` is a
   **queue-depth ceiling** rather than a latency knob (3 `LockTimeoutException` at 800 ms × 20 jobs
   against a 10 s default). **`ResumeLock` therefore gets a non-blocking entry point**, and §4.1
   builds the job on it: the lock reports that the turn is taken, the job decides what to do about
   it. Two consequences ride along — the SQLite driver cannot host more than one worker at all, and
   `tries` goes back to meaning crashes.
2. **Whether the configured cache store actually locks across processes.** `ResumeLock` takes a
   `LockProvider`, which already excludes Laravel's `file` store at the type level. It does not
   exclude an `array` store, which type-checks and locks nothing across processes — the same trap
   DUR030 names for a process-local `lock.factory`. Whether a misconfiguration can be refused at
   boot rather than discovered by a forked journal is a question for the bench.
3. ~~**What class discovery costs without a compiled container.**~~ **Measured — `tasks.md` §1.4.**
   Explicit declaration in `config/durable.php` costs 0,14 ms and does not grow with the
   application; a reflection scan costs 15 ms at a thousand classes and, worse, **loads all of them
   into every process** (1 334 declared classes against 334, +0,9 MB) to find five. **No
   `artisan durable:cache`**: a cached manifest beats the explicit list by 0,11 ms, and
   `config:cache` already caches the file it would duplicate.
4. ~~**Whether Laravel's queue preserves per-execution ordering.**~~ It does not, and the package
   must not depend on it — that is what `ResumeLock` is for. **The rate is measured — `tasks.md`
   §1.5 — and it splits in two.** Spread over many executions, contention is a rounding error
   (0,6 % at sixteen executions per worker), and question 1 would be over-engineering. On a single
   hot execution — one long-lived workflow woken by signals, timers and activity results, which is
   the shape durable execution attracts — 98,8 % of resumes collide, and a 1 s backoff turned 32 s
   of work into 148 s of wall clock. **The non-blocking entry point is justified by the hot case,
   and the backoff has to be configurable**, because at that rate the backoff *is* the latency.

## Temporal on Laravel: the question, not the answer

The measurement above is the whole difficulty. Four Symfony components sit in
`gplanchat/durable-bridge-temporal`'s `require`, and a Laravel application installing them gets a
container it does not use, a kernel it does not boot, and a Messenger it does not run — to reach a
gRPC client that needs none of them.

Three ways out, none free, and this change does not pick one:

- **Accept the weight.** Nothing breaks; Composer resolves; the application carries four unused
  components. Honest, and slightly embarrassing on a page that sells "no framework".
- **Split the bridge.** The gRPC client and worker on one side, the Symfony bundle and its four
  Messenger transports on the other. The right shape, and a breaking change for everyone already on
  `durable-bridge-temporal`.
- **Refuse the combination.** Support in-memory and Illuminate only, and say so the way the Magento
  module says it. Cheapest, and it makes the Laravel entry *less* than the site's own positioning:
  OST003 §3 sells "the same workflow code against a Temporal cluster **or** one SQL database", and
  refusing removes the first half.

**The verdict is the second, and §5.1 has the numbers.** `composer require --dry-run` on a Laravel
application already carrying this package installs **8 packages, 5 of them Symfony**, for ~36 MB —
23 of them `dependency-injection` alone — and the application loads none of the five. The coupled
part of the bridge is **8 files out of 759**: one percent of the package carrying 36 MB for the
other 99 %.

And the split breaks less than it looks. `durable-bundle` already names `Gplanchat\Bridge\Temporal`
in two of its own classes without requiring the package, so the Symfony wiring is already spread
across the two; moving the eight files consolidates it. A Symfony user drops one line from
`bundles.php`, which `UPGRADE.md` can carry.

**It is its own change, not this one** — it edits the Temporal bridge and the Symfony bundle, two
packages a Laravel integration has no business touching, and a breaking change deserves its own ADR.
Until it lands, this package keeps refusing the combination by name: not because the third way was
chosen, but because it is the only one that costs nothing to reverse, and the split is what reverses
it.

## Backends: what the package binds

Two, and the asymmetry with the Magento module is worth naming rather than glossing.

| Backend | Magento module | This package |
|---|---|---|
| In-memory | ✅ | ✅ |
| Temporal | ✅ | see above |
| DBAL | ❌ — Magento ships no Doctrine DBAL | ❌ — a Laravel application has a connection, not a DBAL one |
| Illuminate | ❌ — Magento ships no Illuminate connection | ✅ — this is the whole point |

The two hosts are mirror images: each has exactly one SQL bridge that binds to what it already owns,
and neither can use the other's. That is not a limitation to apologise for; it is DUR030 working —
the journal append and the business write land in one transaction *because* the store sits on the
connection the host already has.

## Naming, and the convention that disagrees

`gplanchat/durable-laravel` against `gplanchat/laravel-durable`, settled in `proposal.md`. The part
worth repeating here is the collision OST003 §3 flags: **`durable-workflow/workflow` is a real,
popular, well-regarded package**, and "Durable" beside "Durable Workflow" on Packagist will be
skimmed as one project. The documentation duty is to name it, compare honestly, and let a reader who
wants an engine-on-queues rather than a backend choice go and take it.

## Order of work

A harness first, because a Tier 1 bootstrap has no unit test that proves it boots, and because the
harness is what answers the four assumptions above. Then the provider and the ports, then the jobs,
then the exclusion under measurement, then the documentation that names the neighbour.

**A harness is not a bench, and the difference is the bill.** What §1 of `tasks.md` needs is a
Laravel skeleton on this machine, long enough to measure four things. A *bench* is that skeleton
tracked, pinned and wired into CI, and OST003 §1 prices it as paid on every upstream release
forever. The first is a cost of writing this change; the second is a permanent liability that
§2 of OST003 says nobody earns until a real user runs one. Measure on a throwaway, and open the
question of tracking it only if the measurements turn out to need repeating.
