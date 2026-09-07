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
| serves | `stock` | `billing` | — | `delivery` |
| calls | `billing` | `stock` | all three | `stock`, **from the workflow that serves** |
| PHP | 8.3 | 8.3 | 8.2 | 8.2 |

All four read the same contract package, `src/DurableDemoContracts/`. **Nothing else travels between
them**: no HTTP client, no shared SDK, no implementation class.

## What Durable brings

**Calling requires nothing.** `WorkflowEnvironment::nexusStub()` reads the contract by reflection.
Serving is wired once per host — and it wires up *outside* Symfony: logistics registers its handlers
with two classes and six lines of `config/durable.php`, the Magento bench wires in `di.xml`. The
serving half of Nexus is not a bundle feature.

**Both shapes are written the same way.** `OrderWorkflow` calls `verify`, then `charge`, on
the same stub. The first returns in milliseconds, served by an ordinary method; the second takes
about fifteen seconds, fulfilled by a workflow on the other side. **The caller's code does not tell
them apart**, and that is the whole point.

**Waiting holds nothing open.** During a debugging session the worker that was to advance the
payment stayed down for four minutes. The operation stayed in `NEXUS_OPERATION_STARTED`, the caller
consumed nothing, and everything completed normally when the worker came back. No connection, no
process, no transaction was waiting. Repeated from Magento: 49 seconds, same result.

## Read as a context map

Explicit Architecture forbids the synchronous cross-context call, and the reason it gives is
availability: B down means A fails, so it reaches for events and eventual consistency instead. The
four-minute measurement above removes that reason. It leaves every other one standing.

What these four applications make operational is the strategic vocabulary:

| DDD | what it is here |
|---|---|
| Bounded context | a Temporal namespace, with its own workers, storage and release cadence |
| Published language | a `#[AsNexusService]` contract and its `#[AsNexusOperation]` methods |
| Context map relationship | a Nexus **endpoint**, created by an operator, pointing a name at a namespace and a task queue |
| Direction of the relationship | which side has an endpoint at all |

Four namespaces, **three endpoints**. An endpoint says where a service is served, so the Magento
bench — which calls three services and serves none — sits on the map with arrows leaving it and
none arriving. The context map is `temporal operator nexus endpoint list`.

**Nine seconds decide the shape of an operation.** A start task carries `request-timeout=8.998s`,
which bounds the answer to *this task* and not the operation; past it the task is redelivered and
the handler starts over. An implemented method is therefore a **query across the boundary** —
`verify` applies billing rules to data the business side already holds — and anything longer is
a **saga step** the other context owns, which is what `#[FulfilsNexusOperation]` declares. The
caller reads one contract and cannot tell which of the two it got.

**Events would have cost a correlation.** Nexus has one — the fulfilling workflow's id *is* the
operation token — but the server owns it, and what delivers the answer is the callback attached at
start. Model the same exchange as a pair of events and that identifier becomes yours to invent,
store, expire, and wrap in a state machine that holds the wait.

## What it does not bring

**Not compensation.** None of the three contracts has an operation that gives back what it took. The
only protection is **call ordering**: `OrderNexusWorkflow` first asks everything that can say no
— check the invoice, plan the round, hold the stock — and only then commits. Both reverse orders
were written first, and measured: a USD order held stock before being refused an invoice, and a
six-parcel order was **charged** before logistics refused to carry it.

**Not idempotency.** A Nexus task gets redelivered; the handler has to hold. The `stock` handler
writes its verdict to `app_durable_stock_reservation`, keyed by order id — replaying the same order
returns the same verdict and does not hold stock twice. That was written by hand; Durable did not
provide it.

**Not an anti-corruption layer.** `OrderWorkflow` reads `$verdict['accepted']` straight off the
stub, so another context's payload shape sits inside the shop's own decision. The demonstration is
flat on purpose, to put the two operation forms side by side. An application would keep the stub in
a driven adapter, declare its port in its own language, and let that adapter build its value
objects: the contracts carry scalars and arrays because the wire is plain JSON, and something has
to turn them into a model.

**Not a small shared kernel.** `src/DurableDemoContracts/` is one, and what keeps it defensible is a
rule rather than a mechanism — it carries operation names and payload shapes, and no domain type
from either side.

## How to run it

```bash
bin/demo-nexus        # first: the namespaces and the Nexus endpoints
demo/run.sh           # then: the eight workers
demo/run.sh --status  # report who is running
demo/run.sh --stop    # stop them
```

`bin/demo-nexus` comes first and is not optional: the workers connect to endpoints that do not
exist until it has created them. Both scripts print the call commands with the right values once
they are done.

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
- **The layered shape.** Every call here is written from workflow code onto a stub. Nothing in the
  repository demonstrates the port-and-adapter arrangement the section above recommends.
- **Security.** The four namespaces sit on the same server with no mTLS and no authorization.
  Cross-team isolation, which is half the Nexus argument, is not demonstrated.

What each app added, one by one, is in
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md). The Nexus
mechanics themselves are described in [Nexus operations](../../nexus/).
