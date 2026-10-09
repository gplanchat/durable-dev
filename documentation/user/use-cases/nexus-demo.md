---
title: Four applications calling each other
weight: 10
---

# Four applications calling each other

## The problem

An order crosses four systems owned by four different teams: the shop holds stock, the business
side invoices, logistics plans and ships, the ERP follows. Each has its own repository, framework
and release cadence. None of them wants to import another one's code.

The usual way to stitch that together (one HTTP API per service, one client per caller, one retry
per client, one timeout per retry) works until one of the four is down mid-transaction. Then
someone has to decide whether to wait, whether to replay, and what happens to what was already
taken.

## What was built

Four applications, four Temporal namespaces, three frameworks. They live in the repository under
[`sylius/`](https://github.com/gplanchat/durable-dev/tree/main/sylius),
[`symfony/`](https://github.com/gplanchat/durable-dev/tree/main/symfony),
[`magento/`](https://github.com/gplanchat/durable-dev/tree/main/magento) and
[`laravel/`](https://github.com/gplanchat/durable-dev/tree/main/laravel).

The table lists the Nexus operations each application serves and calls. A Nexus operation is an
operation served by another service, with its own contract, that a workflow calls the way it calls
an activity (see the [glossary](../../glossary/)).

| | the shop | the business side | the Magento bench | logistics |
|---|---|---|---|---|
| framework | Sylius | Symfony | Mage-OS | Laravel |
| serves | `stock` | `billing` | nothing | `delivery` |
| calls | `billing` | `stock` | all three | `stock`, **from the workflow that serves** |
| PHP | 8.3 | 8.3 | 8.2 | 8.2 |

All four read the same contract package, `src/DurableDemoContracts/`. **Nothing else travels between
them**: no HTTP client, no shared SDK, no implementation class.

## What Durable brings

**Calling an operation requires no wiring.** `WorkflowEnvironment::nexusStub()` reads the contract
by reflection. Serving is wired once per host, including *outside* Symfony: logistics registers its handlers
with two classes and six lines of `config/durable.php`, the Magento bench wires in `di.xml`. The
serving half of Nexus is not a bundle feature.

**Both shapes are written the same way.** `OrderWorkflow` calls `verify`, then `charge`, on
the same stub. The first returns in milliseconds, served by an ordinary method; the second takes
about fifteen seconds, fulfilled by a workflow on the other side. **The caller's code is the same
for both.**

**Waiting holds nothing open.** During a debugging session the worker that was to advance the
payment stayed down for four minutes. The operation stayed in `NEXUS_OPERATION_STARTED`, the caller
consumed nothing, and everything completed normally when the worker came back. No connection, process or
transaction was waiting. The same test from Magento, with 49 seconds of downtime, gave the same
result.

## Read as a context map

Explicit Architecture forbids the synchronous cross-context call, and the reason it gives is
availability: B down means A fails, so it recommends events and eventual consistency instead. The
four-minute measurement above removes that reason. The other reasons still apply.

The four applications give each term of the DDD strategic vocabulary an operational counterpart.

| DDD | what it is here |
|---|---|
| Bounded context | a Temporal namespace, with its own workers, storage and release cadence |
| Published language | a `#[AsNexusService]` contract and its `#[AsNexusOperation]` methods |
| Context map relationship | a Nexus **endpoint**, created by an operator, pointing a name at a namespace and a task queue |
| Direction of the relationship | which side has an endpoint at all |

The demonstration has four namespaces and **three endpoints**. An endpoint says where a service is
served, so the Magento bench, which calls three services and serves none, sits on the map with
arrows leaving it and none arriving. `temporal operator nexus endpoint list` prints the context map.

**A start task has nine seconds.** A start task carries `request-timeout=8.998s`,
which bounds the answer to *this task* and not the operation; past it the task is redelivered and
the handler starts over. An implemented method is therefore a **query across the boundary**, and
`verify` applies billing rules to data the business side already holds. Anything longer is
a **saga step** the other context owns, which is what `#[FulfilsNexusOperation]` declares. The
caller reads one contract, and its code is the same for both kinds.

**Events would need a correlation identifier.** Nexus has one: the fulfilling workflow's id *is* the
operation token. The server owns it, and what delivers the answer is the callback attached at
start. If you model the same exchange as a pair of events, you have to invent that identifier, store
it, expire it and wrap it in a state machine that holds the wait.

## What it does not bring

**Durable does not provide compensation.** None of the three contracts has an operation that gives back what it took. The
only protection is **call ordering**: `OrderNexusWorkflow` first asks everything that can say no
(check the invoice, plan the round, hold the stock) and only then commits. Both reverse orders
were written first, and measured: a USD order held stock before being refused an invoice, and a
six-parcel order was **charged** before logistics refused to carry it.

**Durable does not provide idempotency.** A Nexus task gets redelivered, and the handler has to withstand it. The `stock` handler
writes its verdict to `app_durable_stock_reservation`, keyed by order id: replaying the same order
returns the same verdict and does not hold stock twice. You write that table by hand.

**Durable does not provide an anti-corruption layer.** You write it yourself, and the shop now has one. `OrderWorkflow`
invokes a `PlaceOrder` use case through a `Payments` port. `NexusPayments` implements the port and
hands `verify`'s payload to `Authorisation::fromWire()`, the only method in `sylius/src/` that reads
its `accepted` field. The three benches that call without
that layer show the cost of skipping it: `OrderNexusWorkflow` reads five payloads by key, in the code
that decides. The contracts carry scalars and arrays because the wire is plain JSON, and something
has to turn them into a model.

**No mechanism limits the size of the shared kernel.** `src/DurableDemoContracts/` is a small shared kernel, and what keeps it defensible is a
rule rather than a mechanism: it carries operation names and payload shapes, and no domain type
from either side.

## How to run it

```bash
bin/demo-nexus        # first: the namespaces and the Nexus endpoints
demo/run.sh           # then: the eight workers
demo/run.sh --status  # report who is running
demo/run.sh --stop    # stop them
```

Run `bin/demo-nexus` first. It is required, because the workers connect to endpoints that do not
exist until it has created them. When they finish, both scripts print the call commands with the
right values.

The order in which the workers start does not matter. A late worker makes the execution wait
without making it fail.

Two prerequisites are not obvious. Both are detailed in
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md):

- **a Temporal server with the Nexus APIs enabled.** `temporal server start-dev` works;
  `temporalio/auto-setup:1.25.2` answers `Nexus APIs are disabled` on endpoint creation;
- **two PHP binaries.** 8.3 for the two Symfony applications, 8.2 for Magento and Laravel. The split
  comes from measurement: on the reference machine, no single PHP version has every required
  extension.

## What is not proven

- **Scale.** Four applications on one machine, a `start-dev` server, one order at a time. Nothing
  here says what a Nexus queue does under real load.
- **Recovery from a failing serving handler.** What was measured is a worker that was *down*, not a
  handler that throws halfway through its work.
- **The layered shape, everywhere but the shop.** `sylius/` has its port, adapter and use case.
  The Magento bench and the logistics bench still call stubs from workflow code, so the
  arrangement is demonstrated once rather than shown to hold across hosts.
- **Security.** The four namespaces sit on the same server with no mTLS and no authorization.
  Cross-team isolation, which is half the Nexus argument, is not demonstrated.

[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md) describes
what each application added, one by one. [Nexus operations](../../nexus/) describes the Nexus
mechanics themselves.
