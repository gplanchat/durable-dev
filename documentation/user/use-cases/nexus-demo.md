---
title: Four applications calling each other
weight: 10
---

# Four applications calling each other

## The problem

An order crosses four systems owned by four different teams: the shop holds stock, the business
side invoices, logistics plans and ships, the ERP follows. Each has its own repository, framework
and release cadence. None of them wants to import another one's code.

The usual way to stitch that together — one HTTP API per service, one client per caller, one retry
per client, one timeout per retry — works until one of the four is down mid-transaction. Then
someone has to decide whether to wait, whether to replay, and what happens to what was already
taken.

## What was built

Four applications, four Temporal namespaces, three frameworks. They live in the repository under
[`sylius/`](https://github.com/gplanchat/durable-dev/tree/main/sylius),
[`symfony/`](https://github.com/gplanchat/durable-dev/tree/main/symfony),
[`magento/`](https://github.com/gplanchat/durable-dev/tree/main/magento) and
[`laravel/`](https://github.com/gplanchat/durable-dev/tree/main/laravel).

| | the shop | the business side | the Magento bench | logistics |
|---|---|---|---|---|
| framework | Sylius | Symfony | Mage-OS | Laravel |
| serves | `stock` | `facturation` | — | `livraison` |
| calls | `facturation` | `stock` | all three | `stock`, **from the workflow that serves** |
| PHP | 8.3 | 8.3 | 8.2 | 8.2 |

All four read the same contract package, `src/DurableDemoContracts/`. **Nothing else travels between
them**: no HTTP client, no shared SDK, no implementation class.

## What Durable brings

**Calling requires nothing.** `WorkflowEnvironment::nexusStub()` reads the contract by reflection.
Serving is wired once per host — and it wires up *outside* Symfony: logistics registers its handlers
with two classes and six lines of `config/durable.php`, the Magento bench wires in `di.xml`. The
serving half of Nexus is not a bundle feature.

**Both shapes are written the same way.** `CommandeWorkflow` calls `verifier`, then `encaisser`, on
the same stub. The first returns in milliseconds, served by an ordinary method; the second takes
about fifteen seconds, fulfilled by a workflow on the other side. **The caller's code does not tell
them apart**, and that is the whole point.

**Waiting holds nothing open.** During a debugging session the worker that was to advance the
payment stayed down for four minutes. The operation stayed in `NEXUS_OPERATION_STARTED`, the caller
consumed nothing, and everything completed normally when the worker came back. No connection, no
process, no transaction was waiting. Repeated from Magento: 49 seconds, same result.

## What it does not bring

**Not compensation.** None of the three contracts has an operation that gives back what it took. The
only protection is **call ordering**: `CommandeNexusWorkflow` first asks everything that can say no
— check the invoice, plan the round, hold the stock — and only then commits. Both reverse orders
were written first, and measured: a USD order held stock before being refused an invoice, and a
six-parcel order was **charged** before logistics refused to carry it.

**Not idempotency.** A Nexus task gets redelivered; the handler has to hold. The `stock` handler
writes its verdict to `app_durable_stock_reservation`, keyed by order id — replaying the same order
returns the same verdict and does not hold stock twice. That was written by hand; Durable did not
provide it.

## How to run it

```bash
demo/lancer.sh            # start the eight workers
demo/lancer.sh --etat     # report who is running
demo/lancer.sh --arreter  # stop them
```

Start order does not matter: a late worker makes things wait, it does not make them fail.

Two prerequisites you would not guess, detailed in
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md):

- **a Temporal server with the Nexus APIs enabled.** `temporal server start-dev` will do;
  `temporalio/auto-setup:1.25.2` answers `Nexus APIs are disabled` on endpoint creation;
- **two PHP binaries.** 8.3 for the two Symfony apps, 8.2 for Magento and Laravel — measured, not
  timid: on the reference machine no single version has the intersection of required extensions.

## What is not proven

- **Scale.** Four applications on one machine, a `start-dev` server, one order at a time. Nothing
  here says what a Nexus queue does under real load.
- **Recovery from a failing serving handler.** What was measured is a worker that was *down* — not a
  handler that throws halfway through its work.
- **Security.** The four namespaces sit on the same server with no mTLS and no authorization.
  Cross-team isolation, which is half the Nexus argument, is not demonstrated.

What each app added, one by one, is in
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md). The Nexus
mechanics themselves are described in [Nexus operations](../../nexus/).
