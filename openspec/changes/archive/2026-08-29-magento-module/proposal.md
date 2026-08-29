## Why

[OST003 §3](../../../documentation/ost/OST003-php-ecosystem-integrations.md) puts Magento in
Tier 1 — *foreign container, foreign queue, a package written from the bootstrap up* — and states
the failure the integration exists to remove:

> Cron plus `MessageQueue` consumers, and the failure every integrator has seen is a consumer that
> dies half way through an order. Conservative community, enterprise budgets, slow adoption — and
> the bench is already in this repository (`magento/`).

That is the whole case. Magento already runs long business processes asynchronously; what it has no
answer for is a consumer that stops in the middle of one. The order is charged, the stock is not
reserved, and the operator finds out from the customer. Re-running the consumer re-charges the card.

[OST004 §5](../../../documentation/ost/OST004-what-is-not-built-yet.md) lists Magento as needing *"a
bootstrap"* with nothing in its **Blocked on** column. This change is that bootstrap.

### What the bench actually is

OST004's claim that the bench is *"already in `magento/`"* is true on a developer machine and false
in the repository: `git ls-files magento` returns **zero** files, where `sylius/` returns 220. The
overlay exists — `composer.json` pinned to `mage-os/product-community-edition:2.2.0`, a
`compose.yaml` with MySQL, OpenSearch, Redis, Temporal and the Temporal UI, a README, and a
host-extension check script — but nobody who clones this repository has one.

It also names a package that does not exist. Its `composer.json` requires `gplanchat/durable-module`
from the path repository `../src/DurableModule`, and `src/DurableModule` is not there.

So the bootstrap has two halves: **write the module**, and **land the bench that proves it runs**.

## What Changes

- A Magento module SHALL register workflow and activity classes with the Durable runtime, without
  the attribute autoconfiguration that `DurableBundle` gets from Symfony and Magento's container
  does not provide.
- Activity dispatch and workflow resume SHALL ride **Magento's own** `MessageQueue`, declared in
  `communication.xml`, `queue_topology.xml`, `queue_publisher.xml` and `queue_consumer.xml`, rather
  than a second queue introduced beside it.
- Workers SHALL be `bin/magento` console commands, drained by the consumer runner an operator
  already supervises — `magento cron:run` and `queue:consumers:start`, not a process model to learn.
- ~~Two consumers SHALL NOT replay the same execution at once, over `LockManagerInterface`.~~
  **Withdrawn 28/08**, with the queue it protected. The hazard
  [DUR030](../../../documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md) names for
  the DBAL backend — a forked journal, duplicated activities — needs two resumes of one execution to
  be two queue messages. On the only durable backend this host reaches, a resume is a Temporal
  workflow task the server already serialises, and nothing of Durable rides Magento's queue. ⚠ The
  bullet returns with a host-native journal, if one is ever added.
- The module SHALL support the **in-memory** and **Temporal** backends, and no other. This is the
  promise the published site already makes, with its reason: Magento ships neither Doctrine DBAL nor
  Illuminate's connection, so neither SQL bridge has anything to bind to. State lives in the
  cluster, or it lives in one process.
- The `magento/` overlay SHALL be tracked, the way `sylius/` is: sources yes, `vendor/` no.
- **BREAKING** no. Nothing already shipped changes shape.

### The package name is decided here

The repository currently gives three answers, and no two agree:

| Where | Name |
|---|---|
| The published home page selector | `gplanchat/durable-magento?` — the `?` marks it undecided |
| `magento/composer.json`, and its path repository `../src/DurableModule` | `gplanchat/durable-module` |
| Magento's own convention for a module package | `gplanchat/module-durable` |

`gplanchat/durable-module` is the weakest of the three: it names no host, and on Packagist beside
`durable-bundle` and `durable-plugin` it reads as *the* module of something rather than the Magento
one. The choice is between naming the family first (`durable-magento`, consistent with the four
packages already published) and naming the host's convention first (`module-durable`, what a Magento
developer's eye expects in `vendor/`).

This change picks **`gplanchat/durable-magento`**: the family prefix is what a reader of the site,
the docs and Packagist has already learned, and the four shipped packages all lead with it. The
Magento convention governs the *directory* under `app/code` and the module's declared name in
`registration.php` — `Gplanchat_Durable` — which is where a Magento developer actually looks.

The bench and the home page both follow. Neither is source of truth today; after this change the
proposal is.

### Not in scope

- **The DBAL backend on Magento.** Adapting Durable's SQL journal to `ResourceConnection` is a
  fourth adapter family, not a bootstrap. It would also contradict a promise the site makes today,
  so changing it is a decision of its own with the wizard, `ALLOWED.magento` and OST003 to update.
- **An admin dashboard.** `durable-plugin` observes Sylius runs; the Magento equivalent is a
  separate change, and it needs this one to exist first.
- **Adobe Commerce.** The bench is Mage-OS, which installs without Adobe credentials. Whether the
  module runs unmodified on Adobe's distribution is a question for the integration suite once there
  is something to run.
- **Multi-store and multi-website semantics.** A workflow started in one store view is a scoping
  question this change does not open.
