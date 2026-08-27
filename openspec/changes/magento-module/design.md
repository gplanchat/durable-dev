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
- what a `queue_consumer.xml` consumer does when its process dies mid-message — redelivery, dead
  letter, or silence;
- whether `LockManagerInterface`'s default implementation is shared across consumer processes, or
  per-process the way an unconfigured Symfony lock factory is;
- how a Magento consumer behaves against a long-poll transport, which is what the Temporal bridge's
  workers are.

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

This is the design's only real invariant, and it is inherited rather than discovered. It still gets
probed (task 1.3) before it is trusted.

## Backends: two, and the reason is not laziness

The published site already says Magento reaches **in-memory and Temporal only**, and gives the
reason in the wizard itself:

> Magento ships neither SQL layer, so both are off — the state lives in the cluster instead.

That is accurate. `Magento\Framework\App\ResourceConnection` is neither Doctrine DBAL nor
Illuminate's connection, and the two SQL bridges bind to those two types. Making the journal speak
`ResourceConnection` is a fourth adapter family — a change of its own, with the wizard,
`ALLOWED.magento` and OST003 to update behind it.

Until then the module refuses a DBAL or Illuminate configuration **at startup, by name**, the way
the DBAL backend refuses Nexus: `NexusUnsupportedByBackendException` names the backend and says what
to do instead, rather than leaving a workflow waiting on a result nobody will produce.

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
