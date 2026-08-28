# Tasks

## 1. A harness, and the four things it has to answer

A Tier 1 bootstrap has no unit test that proves it boots. Before the package has a shape, a throwaway
Laravel skeleton has to answer the four questions `design.md` records as assumed — and each answer
either confirms a design or replaces it.

- [ ] 1.1 A Laravel skeleton on this machine with `gplanchat/durable` and
      `gplanchat/durable-bridge-illuminate` installed by path repository, `artisan migrate` creating
      the four tables. Throwaway by default; tracking it is §6.3's question, not this one.
- [ ] 1.2 **Measure what a waiting job costs.** Two workers, one execution, contention forced.
      Compare `ResumeLock::around()` blocking against `$this->release($delay)`: worker slots held,
      `--tries` consumed, what lands in `failed_jobs`. Record the numbers, not the preference.
- [ ] 1.3 **Probe whether the configured store locks across processes.** Confirm `array` type-checks
      as a `LockProvider` and fails to exclude across two worker processes. Decide whether the
      provider can refuse it at boot, and whether that refusal can be wrong (a single-worker
      deployment where `array` is correct).
- [ ] 1.4 **Measure class discovery.** Boot cost of an explicit `config/durable.php` list against a
      scan, on an application with a hundred classes and none of them workflows. Whether a cached
      manifest earns a command depends on this number.
- [ ] 1.5 **Measure the collision rate.** Under a realistic worker count, how often two resumes of
      one execution are dequeued together. This decides whether 1.2 is the design or a detail.

## 2. The package boots

- [ ] 2.1 `src/DurableLaravel/` with a `composer.json` naming `gplanchat/durable-laravel`, its
      `extra.laravel.providers` entry, a `LICENSE` at the prefix root (DUR020 requires it of every
      split), and the prefix added to `bin/splitsh-publish.sh` — with its satellite repository
      created *before* the line lands, the lesson of `durable-bridge-illuminate`.
- [ ] 2.2 A `DurableServiceProvider` found by package auto-discovery that binds the four storage
      ports from `config/durable.php`, publishable under `--tag=durable-config`.
- [ ] 2.3 A workflow class written for `durable-bundle` runs unmodified, resolved by the name its
      attribute declares. An undeclared type fails naming itself and where types are declared.

## 3. Work rides Laravel's queue

- [ ] 3.1 Activity dispatch as an `illuminate/queue` job on the application's own connection.
- [ ] 3.2 Workflow resume as a job, drained by `php artisan queue:work` and nothing else.
- [ ] 3.3 Timers on the queue's own delay, the way the DBAL backend rides Messenger's `DelayStamp`.
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
