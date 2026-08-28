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
3. **What class discovery costs without a compiled container.** Symfony resolves `#[Workflow]` at
   compile time, once. Laravel has no equivalent, and scanning at boot on every request is the
   wrong answer for an application that never starts a workflow in a web request. Explicit
   declaration in `config/durable.php` is the assumed answer; whether a cached manifest
   (`artisan durable:cache`, in the shape of `route:cache`) is worth its own command is not decided.
4. **Whether Laravel's queue preserves per-execution ordering.** It does not, and the package must
   not depend on it — that is what `ResumeLock` is for. What is unmeasured is the *rate*: how often
   two resumes of one execution actually collide under a realistic worker count, which decides
   whether question 1 above is a micro-optimisation or the design.

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

The third is the default this change ships under, because it is the only one that costs nothing to
reverse. It is a starting position, not a verdict, and the proposal says so.

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
