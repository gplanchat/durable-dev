# DUR046 — Magento: a Tier 1 host, and the four things it changed about the core

## Status

Accepted

## Context

[OST003 §3](../ost/OST003-php-ecosystem-integrations.md) puts Magento in Tier 1 — *foreign
container, foreign queue, a package written from the bootstrap up* — and names the failure the
integration exists to remove: a consumer that dies half way through an order. The order is charged,
the stock is not reserved, and re-running the consumer charges the card again.

A Tier 1 bootstrap has no unit test that proves it boots: a Magento module cannot be tested against
anything smaller than Magento. So the bench came first and every decision below was **measured on
it** before it was written down. Four of them overturned what the design had assumed.

## Decision

### The names line up, because PSR-4 is what keeps them from drifting

`gplanchat/durable-magento` on Packagist; the module is **`Gplanchat_DurableModule`** and the
package autoloads under **`Gplanchat\DurableModule\`** — one root, matching the directory it lives
in.

That correspondence is not cosmetic. Magento resolves an admin action **by convention from the
module name**: `ActionList::get()` composes `Gplanchat_DurableModule` + `\Controller\Adminhtml\…`
and never consults the autoloader's map. As long as the module name and the package's PSR-4 root
agree, there is nothing extra to declare.

They did not agree at first. The module was `Gplanchat_Durable` while the package autoloaded under
`Gplanchat\Durable\Magento\`, which forced a **second** `psr-4` entry for the controller
directory alone. The symptom was thoroughly misleading: the route was declared,
`getRouteFrontName()` answered, **the menu rendered** — and Magento served its 404 inside the admin
chrome, so every sign pointed at a declaration that was in fact correct.

The lesson is not about Magento. Two names for one thing drift, and the special case that papers
over the drift is how the drift becomes permanent. PSR-4 exists to make it impossible; the fix was
to let it.
### Two backends, and **Composer** refuses the others

Magento reaches `memory` and `temporal`, and this is final rather than provisional:
`Magento\Framework\App\ResourceConnection` is neither Doctrine DBAL nor Illuminate's connection, and
the two SQL bridges bind to those two types.

The refusal is six lines of metadata, not code:

```json
"conflict": {
    "gplanchat/durable-bridge-dbal": "*",
    "gplanchat/durable-bridge-illuminate": "*"
}
```

`composer require gplanchat/durable-bridge-dbal` beside the module ends in *"Conclusion: remove
gplanchat/durable-magento"* and writes nothing. The incoherent installation never exists, so no
process boots into it — earlier and harder than a refusal at startup, which necessarily arrives
after someone has installed the wrong thing.

A first version had built that refusal in code, with a backend-name configuration surface. Both are
gone. **What decides is what is installed and configured**, never a string that can be mistyped.
Where the journal lives is decided the same way: by the presence of `durable/temporal/dsn` in
`env.php` — a connection string, not a backend name.

The one thing `conflict` cannot carry is the *reason*. That stays in `ALLOWED.magento`, in the
selector, and here.

### Nothing rides Magento's `MessageQueue`

The change set out to put resumes and activity dispatch on the host's own queue. **It was
abandoned, and the measurement is the argument.**

`TemporalWorkflowCommandBuffer` schedules an activity as a `ScheduleActivityTaskCommandAttributes`:
a Temporal command, on a Temporal task queue. `EventStoreCommandBuffer` — the one that puts an
`ActivityMessage` on the host's queue — is the **non-Temporal** path. Magento has no native journal
and will not get one. So with Temporal the host's queue carries neither activities nor resumes; with
`memory`, everything is one process, where a queue carries nothing that outlives it.

Tasks 4 and 5 were never a sequence. They were alternatives, and only one is reachable here.

**The workers are `bin/magento` commands**, `--role=journal|activity`, and that is not a preference
either. §1.5 measured what Magento's queue does to a message held too long: the retry timer looks at
`updated_at` and never asks whether the first consumer has finished. Two live processes ran the same
message at once — a duplication during a success, not a redelivery after a failure. A worker holds
its task by long poll, so **a worker cannot be a queue message**.

Two probes are worth keeping beside that one, because both fail silently:

- a consumer killed mid-message leaves the row `IN_PROGRESS` with zero trials and a `queue_lock`
  row. No dead letter, nothing logged, and a fresh consumer waits beside it without taking it.
  Recovery needs **two** cron jobs, and their order decides: a retry that lands while the lock still
  stands makes `Consumer` **acknowledge the message without dispatching it**;
- Magento's message encoder, given a Durable transport object, returns `[]` **without throwing** —
  the publisher succeeds, the execution id is gone, and the consumer fails at decode in another
  process. `string[]` drops associative keys instead.

### The lock is shared, and its use case evaporated

`LockManagerInterface` **is** shared across processes — measured with two processes, not by reading
the class, and a `SIGKILL`ed holder releases it because `GET_LOCK` dies with its connection. Two
findings came with it: the container hands out a `Lock\Proxy` that names no backend until it has
worked, so a startup check cannot read `get_class()`; and `Backend\Database::lock()` returns `true`
**without locking anything** when `isDbAvailable()` is false — a lock that always says yes.

What that lock was for — two consumers dequeuing one execution — went away with the queue. The
measurement stands; the guard it justified is not built, and does not need to be. If a host-native
journal ever appears, it comes back with it.

## Consequences

### A host integration that improved the core three times

None of these was on the plan. Each was found because a host without Symfony tried to run the
component, and each was fixed in `gplanchat/durable` rather than worked around in the module:

| what moved | why it mattered |
|---|---|
| `PayloadToContractMethodInvoker` | pure PHP in the bundle's package; two hosts needed it word for word |
| `TimerWakeDelayCalculator` | **the core imported the Symfony bundle** — a fatal *class not found* on the first resume of any host without it |
| the resume orchestration, 279 lines | `ResumeWorkflowHandler` and `FireWorkflowTimersHandler`: 15 core imports each, and 21 lines of Symfony that reduced to a UUID the core already had and one port |

The second is the one to remember. `gplanchat/durable` requires neither the bundle nor any bridge,
and under Symfony the class is always there, so nothing showed. A guard now walks the 183 files of
`src/Durable` and fails on any real `use` of a host or a bridge. Six hosts on the selector do not
ride the bundle; every one of them would have met that error.

### The failure OST003 names is gone, and the transcript is the proof

```
--- la carte est débitée : ORD-acceptation-1787917138
=== on tue les DEUX workers, en pleine réservation
=== on relance les workers
acceptation-1787917138 -> 'notify:charge:ORD-acceptation-1787917138'
VERDICT : débits = 1
```

And from a real order: `sales_order_place_after` starts the execution **on the cluster** and returns.
Starting it in the request would kill it with the request, which is the failure itself. The observer
never throws — a placed order stays placed, and refusing the sale would not give the money back.

### What this ADR does not claim

The module is not published: `src/DurableModule` is absent from the splits list, so
`gplanchat/durable-magento` does not exist on Packagist. CI resolves the module against five
Mage-OS × PHP pairs but **does not boot it**: every claim about the running module rests on a bench
transcript. And whether it runs unmodified on Adobe's distribution is untested — the bench is
Mage-OS.
