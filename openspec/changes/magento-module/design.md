# Design

## What was probed, and what was not

This section leads because the house rule says it should, and here it mostly reports an absence.
Every server rule this repository encodes was probed first; **nothing on the Magento side has been
probed yet**, and the design says where it is therefore guessing.

**Measured, in this repository:**

| | |
|---|---|
| `git ls-files magento` | **0** — the overlay is untracked. `sylius/` returns 220. |
| `magento/composer.json` | requires `mage-os/product-community-edition:2.2.0`, `gplanchat/durable-bridge-temporal:@dev`, `gplanchat/durable-module:@dev` |
| its path repositories | `../src/DurableModule`, `../src/Bridge/Temporal`, `../src/Durable` |
| `src/DurableModule` | **does not exist** |
| `magento/vendor/mage-os/` | **363 packages** — the install completed. An earlier line here said the opposite, having looked under `vendor/magento/`, which Mage-OS does not use |
| `magento/compose.yaml` | MySQL, OpenSearch, Redis, Temporal, Temporal UI |

**Not measured, and therefore not encoded as invariants below:**

- ~~whether `mage-os/product-community-edition:2.2.0` installs on this PHP and reaches a working
  `bin/magento`~~ — **answered by §1.2**: it does, and it was already installed. What was broken
  was the bench's default ports, held by the benches beside it;
- ~~what a `queue_consumer.xml` consumer does when its process dies mid-message~~ — **answered by
  §1.3**: *silence*, then a redelivery that takes a day and can swallow the message whole. Below;
- ~~whether `LockManagerInterface`'s default implementation is shared across consumer processes~~ —
  **answered by §1.4**: it is, and the answer came with a caveat the design had not seen. The bench
  configures `lock.provider: db`; the container hands out a `Lock\Proxy` that names nothing until
  it has worked, and `Backend\Database` behind it refuses a second process the lock a first holds.
  A `SIGKILL`ed holder releases it, because `GET_LOCK` dies with its connection. The caveat:
  `Backend\Database::lock()` **returns `true` without locking** when `isDbAvailable()` is false;
- ~~how a Magento consumer behaves against a long-poll transport~~ — **answered by §1.5**: it holds
  the message fine, and the queue hands the same message to a second consumer while it is still
  working it. Below.

Task 1 exists to answer these before task 2 writes anything that depends on them. A false invariant
inside this module would be worse than a primitive, for the reason `openspec/config.yaml` already
gives: it rejects correct code.

## What a Tier 1 host costs, concretely

`DurableBundle` is 5 handlers, a compiler pass, an extension and a console command, and most of its
weight is Symfony doing the work. Against Magento's container none of that is free:

| What Symfony gives | What Magento needs instead |
|---|---|
| `#[Workflow]` / `#[Activity]` autoconfiguration by tag | A discovery mechanism — `di.xml` argument injection over an explicit list, or a compiler-pass equivalent |
| Messenger routing in `messenger.yaml` | `communication.xml`, `queue_topology.xml`, `queue_publisher.xml`, `queue_consumer.xml` |
| `messenger:consume` | `bin/magento queue:consumers:start`, plus whatever `cron:run` has to trigger |
| `symfony/lock` with a configured store | `Magento\Framework\Lock\LockManagerInterface`, over a store shared by every consumer |
| `Handler/ResumeWorkflowHandler` and its four siblings | Magento consumer classes with the same five roles |

The module's job is to provide the right-hand column and **nothing else**. Everything below the
ports — replay, the command buffer, the journal, failure classification — is `gplanchat/durable`
unchanged, exactly as the DBAL bridge leaves it unchanged.

## Three host constraints, found by trying

None was in the design before the module was written, and each cost a debugging round:

**Magento's container forbids `final`.** It generates an `Interceptor` subclass for every class it
instantiates, to carry plugins. A `final` class fails compilation with *"cannot extend final
class"*, and the message does not say the keyword is the cause. This repository writes `final`
everywhere; inside the module, the classes the container builds cannot. The ones it does not build —
the workflow, the runtime it assembles — stay `final`.

**Mage-OS audits path repositories.** `composer-dependency-version-audit-plugin` refuses a package
resolved from a local path when a higher version of the same name exists on packagist.org — a
dependency-confusion guard. `gplanchat/durable` is both published and provided by path here, so it
trips on every bench install. The bench disables the plugin for itself, which is defensible because
the path repositories *are* its source of truth; a consumer's project should keep it on.

**A module in `app/code` does not autoload on this distribution.** Found while putting §1.3's probe
subject in the bench rather than in the published package. `ComponentRegistrar` registers it —
`module:status` says *enabled* — and the classes still do not resolve: Mage-OS's root
`composer.json` declares `Magento\Setup\` and nothing else, where a classic Magento install carried
`psr-0: {"": ["app/code/"]}` and made every vendor under `app/code` resolvable for free. Registering
a component and autoloading its classes are two different mechanisms, and only the first is
automatic. The bench adds its own `psr-4` entry; anyone bootstrapping a Mage-OS bench with a local
module will hit this and read it as a broken module.

## The one hazard that is not a port

Temporal serialises workflow tasks for one execution server-side. Magento's queue does not, and
neither does the DBAL backend — which is why DUR030 carries `SingleResumeLockMiddleware` and a
warning that it *"is only as safe as your lock store"*.

The same sentence has to be true here, and the same failure is available: two `queue:consumers:start`
processes dequeue two resumes of one execution, replay the same fiber in parallel, and each appends
its own commands. Duplicated activities, forked journal.

So the module carries a per-execution lock over `LockManagerInterface`, and it **fails at startup**
rather than at the moment of the collision if the configured lock provider is not shared. Magento's
default `Magento\Framework\Lock\Backend\Database` is shared by construction — a `GET_LOCK` on the
application database — which is a better default than Symfony's, but the module must not assume the
default is what is configured.

### What a dying consumer leaves — §1.3, measured

The bench has no AMQP, so this is `Magento\MysqlMq`, and none of it reads like AMQP.

The instrument is a bench-local module, `magento/app/code/Gplanchat/DurableProbe` — a topic whose
handler does nothing but sleep. It stays out of `gplanchat/durable-magento` on purpose: §4.1's five
roles will be written in the published module, and this is not one of them.

A consumer killed mid-message leaves the row at **`IN_PROGRESS`, `number_of_trials = 0`**, and a row
in **`queue_lock`**. No dead letter, no error in any log, and no other consumer takes it: a fresh
`queue:consumers:start` waited 25 s beside it and picked up nothing. Silence is the answer.

Getting it back needs **two** cron jobs, and they are not the same job:

| | schedule | what it does |
|---|---|---|
| `mysqlmq_clean_messages` | `30 6,15 * * *` | `IN_PROGRESS` → `RETRY_REQUIRED`, once `retry_inprogress_after` has passed — **1440 minutes**, a day |
| `messagequeue_clean_outdated_locks` | hourly | empties `queue_lock` |

**And their order decides whether the message is run or silently swallowed.** If the retry lands
while the lock is still there, `MessageController::lock()` throws `MessageLockException`, and
`Consumer` catches it by **acknowledging the message without dispatching it** — the row goes
`COMPLETE`, the handler never runs, nothing is logged. Measured: with `retry_inprogress_after`
lowered to one minute, the redelivered message went `COMPLETE` in about a second and the handler's
trace stayed shut. The lock row was `md5('durable.probe-1')` to the character.

Purge the lock first and redelivery works as advertised: the handler restarted **from the
beginning**, `number_of_trials` at 1. Nothing resumes mid-handler — which is exactly why the journal
has to be what resumes, and not the message.

So the shipped configuration is saved by its own sloth: a day of retry delay outlives an hourly lock
purge. **A shop that shortens `retry_inprogress_after` to recover faster walks straight into the
silent drop** — and the message it would drop is a workflow resume. The module cannot leave that to
chance, and §4.3's test is where it gets pinned.

*(Read, not measured: `releaseOutdatedLocks()` computes its cutoff with `$date->add()` where `sub()`
would be the sane reading, so the hourly job deletes **every** lock rather than the outdated ones.
Consistent with what the run did — it removed a four-minute-old lock.)*

### What a long message costs — §1.5, measured

Two of the three worries were unfounded, and the third is worse than the worry.

**The runner does not mind.** A handler holding a message for 200 s ran to completion, `FIN` in the
trace, row `COMPLETE`. `queue:consumers:start` imposes no deadline of its own. **Nor does MySQL**:
the bench's `wait_timeout` is `28800` — eight hours, against a long poll measured in seconds.

**But the retry timer never asks whether the first consumer finished.** It looks at `updated_at` and
nothing else. With `retry_inprogress_after` shortened to a minute, one message produced this:

```
01:00:48 worker-longue-poll DÉBUT   pid=442111 tient=200s
01:02:03 worker-longue-poll DÉBUT   pid=445235 tient=200s     ← 442111 travaille toujours
```

Two live processes, one message body, both handlers running. Not a redelivery after a failure — a
**duplication during a success**.

The consequence names the worker's shape. A long-poll worker holds its message by construction, and
it outlives a day the way any worker does; the shipped 1440-minute delay is therefore not a floor
that saves it, only the hour at which the duplicate arrives. **So the worker cannot be a queue
message.** §5.1 already says the workers are `bin/magento` commands drained by the operator's own
supervisor — that was a preference before this measurement, and it is a constraint after it.

And for task 4: **Magento's queue offers no mutual exclusion whatsoever**, not even one delivery at a
time for one message. §1.4's per-execution lock is not a refinement on top of the queue's guarantees;
it is the only thing standing between two consumers and a forked journal.

*(Method, paid for once: a dirty queue answers a different question. The first run's second consumer
picked up an older leftover message instead of the one under test, and the trace looked like a
non-result. `probe-queue.php purge` exists because of it.)*

## The one hazard that is not a port — inherited, and now with a second one under it

This is the design's only real invariant, and it is inherited rather than discovered. **§1.4 probed
it and it holds** — two processes, `magento/probe-lock.php`, the second refused while the first
holds. A killed holder releases it too, so a crashed consumer costs a resume, not a wedged
execution.

What the probe found that reading the class would not: the container hands out a
`Magento\Framework\Lock\Proxy`, which names no backend until it has been made to work. A startup
refusal cannot read `get_class()` on the lock manager and conclude anything. And
`Backend\Database::lock()` returns `true` **without taking any lock** when
`DeploymentConfig::isDbAvailable()` is false — a lock that always says yes, which is exactly the
failure this section exists to prevent and the shape §2.3's refusal has to recognise.

## Backends: two, and the reason is not laziness

The published site already says Magento reaches **in-memory and Temporal only**, and gives the
reason in the wizard itself:

> Magento ships neither SQL layer, so both are off — the state lives in the cluster instead.

That is accurate. `Magento\Framework\App\ResourceConnection` is neither Doctrine DBAL nor
Illuminate's connection, and the two SQL bridges bind to those two types. Making the journal speak
`ResourceConnection` is a fourth adapter family — a change of its own, with the wizard,
`ALLOWED.magento` and OST003 to update behind it.

Until then the module refuses a DBAL or Illuminate configuration **by name**, the way the DBAL
backend refuses Nexus: `NexusUnsupportedByBackendException` names the backend and says what to do
instead, rather than leaving a workflow waiting on a result nobody will produce.

**And "at startup" means something weaker here than it does under a bundle — §2.3, built.** Magento's
container has no equivalent of a bundle extension, and `setup:di:compile` instantiates nothing, so
there is no point at which a configuration error can fail the build. What the module can do, and
does, is refuse where a process **assembles the runtime** — at the boot of a `bin/magento` command
and at the boot of a consumer, before any workflow waits on anything. Every entry point routes
through the one factory rather than each carrying its own guard. That satisfies what the task asks
for; it is not compile-time validation, and the design should not be read as promising it.

The vocabulary is the selector's, not the bundle's: `memory` and `temporal`, because
`ALLOWED.magento` already says `['memory', 'temporal']` and §6.2 will have to agree with it. The
bundle's `in_memory` names the type of an *event store* — a different axis, and conflating the two
would make the configuration and its documentation drift apart.

A fourth refusal fell out of putting the check at the assembly point, and it is worth more than the
three the task asked for: a configuration naming `temporal` is refused too, because the module does
not wire it yet. Serving in-memory in its place would lose everything at process exit and announce
nothing — the same shape as §1.4's lock that always says yes.

## Naming, and the two conventions that disagree

`gplanchat/durable-magento` on Packagist; `Gplanchat_Durable` in `registration.php`, under
`app/code/Gplanchat/Durable`.

The first follows the four packages already published and the site that documents them. The second
follows what a Magento developer's eye expects, and it is the one that appears in
`bin/magento module:status`. They disagree only in the place where nobody has to choose: a Composer
package name and a Magento module name are different strings by design, and the module's own
`composer.json` maps one to the other.

The bench's `gplanchat/durable-module` is dropped. It names no host, and beside `durable-bundle` and
`durable-plugin` it reads as *the* module of the family rather than the Magento one.

## Order of work

The bench comes first, and not for comfort: a Tier 1 bootstrap has no unit test that proves it
boots. `DurableBundle` can be tested against a Symfony kernel in-process; a Magento module cannot be
tested against anything smaller than Magento. So the bench is the test harness, and until it
installs, every subsequent slice is unverifiable by construction.

That inverts the usual TDD order at exactly one point, and only there: task 1 builds the instrument,
tasks 2 onward are Red-Green as usual against it.
