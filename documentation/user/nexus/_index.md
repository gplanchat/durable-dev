---
title: Nexus operations
weight: 29
---

# Nexus operations

A Nexus operation is an operation that another team, namespace or deployment serves behind its own
contract (see the [glossary](../glossary/)). A workflow calls it the way it calls an activity, and
neither side knows the other's workflows. Durable covers both roles: it **calls** operations and it **serves** them.

Serving requires the **Temporal backend**. The in-memory and DBAL backends have no cross-namespace
route, and they report it with an error; see [Backends](../backends/).

You can keep a SQL journal (the append-only record of everything a workflow run decided and
received) while serving. `durable.backend: dbal` with a `temporal.dsn` declares the cluster
reachable while the SQL journal stays the source of truth. A shop whose dashboard reads DBAL
serves a Nexus operation this way, and the dashboard keeps reading the same data. Calling works the
other way round: a workflow schedules the operation, and a workflow can only schedule one if its
journal **is** the cluster.

---

## Calling an operation

```php
#[AsNexusService('billing')]
interface BillingContract
{
    #[AsNexusOperation('charge')]
    public function charge(string $order, int $amount): array;
}
```

```php
$billing = $env->nexusStub(BillingContract::class, endpoint: 'payments');

$receipt = $env->await($billing->charge('ORD-42', 1200));
```

You write the contract **once**, and both sides of the boundary read it: the caller derives a
typed stub from it, and the handler implements it. You never retype an operation name as a string,
so a typo is a type error instead of an operation waiting for a handler whose name never matches.

The endpoint is a parameter of the stub, and the contract does not mention it. The endpoint says
*where* the service is served. That is a deployment concern and changes between environments; the
contract stays the same.

`nexusStub()` assembles the call and `await()` waits for it, as everywhere else in a workflow; see
[Creating a workflow](../workflows/).

The payload travels **as you wrote it**. Durable adds no envelope around it, so a handler written
with the Go, Java or TypeScript SDK reads the fields it declares.

This also limits what a contract may declare. The payload is plain JSON, keyed by parameter name,
and the other side decodes it **associatively**. A parameter typed as an object arrives as an
array, and the handler raises a `TypeError` at call time, and not when you write the contract.
Contracts therefore carry scalars and arrays. In PHP, an *empty* associative array encodes as `[]`
and not `{}`, so a field that can be empty needs a companion field saying whether to read it at all.

Whether the handler answers immediately or hours later, the calling code is the same: the workflow
waits on the operation until its result arrives.

---

## Serving an operation

A handler implements the contract, or the part of it that it answers immediately:

```php
use Gplanchat\Durable\Attribute\AsNexusServiceHandler;

#[AsNexusServiceHandler(contract: BillingContract::class)]
final class Billing implements BillingServed
{
    public function verify(string $order): array
    {
        return $this->rules->check($order);
    }
}
```

How you register the handler depends on the host. On Symfony, put `#[AsNexusServiceHandler]` on a
service and the bundle autoconfigures it. Laravel does not discover handlers by their attribute: it serves the
classes listed in `nexus.handlers` in `config/durable.php`, each as `handler => contract`, or as
the handler class alone, whose `#[AsNexusServiceHandler]` then names the contract
([the pair form below](#serving-is-host-work-and-it-is-not-symfony-work)). Magento lists each
handler in the `nexusHandlers` argument of `RuntimeFactory` in `di.xml`, and its
`#[AsNexusServiceHandler]` names the contract. See [who registers what, per host](../getting-started/#register-workflows-and-activities).

### Why the contract comes in two pieces

An operation fulfilled by a workflow has no handler body. Durable starts the workflow, and the
server delivers its result. The contract therefore splits into two interfaces: the one a handler
**implements**, and the one that **extends** it for the caller.

```php
#[AsNexusService('billing')]
interface BillingServed                        // answered immediately
{
    #[AsNexusOperation('verify')]
    public function verify(string $order): array;
}

#[AsNexusService('billing')]
interface BillingContract extends BillingServed // + what a workflow fulfils
{
    #[AsNexusOperation('charge')]
    public function charge(string $order, int $amount): array;
}

#[AsWorkflow('Charge')]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class Charge { /* … */ }
```

Without the split, PHP requires a body for `charge()` on the handler: an empty method that exists
only to show there is nothing to write. With the split, the workflow claims the operation in its
own class, where its code lives, and the caller's contract still declares every operation so the
stub can call them all.

### Answering now, or answering later

A handler answers in one of two forms, and choosing between them is the main decision when you
serve an operation.

```php
// Now: the handler returns the contract's own type.
public function verify(string $order): array { … }

// Later: a workflow claims the operation, and produces the result.
#[AsWorkflow('Charge')]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class Charge { … }
```

**A handler has roughly nine seconds.** This budget covers answering *this task*, whatever the
operation's own budget. The caller's `scheduleToClose` may be five minutes, while the task itself
carries a `request-timeout` of about nine seconds. When a handler is still working at expiry, its
task is redelivered and the handler starts over. Measured redeliveries: ~9.9 s, ~20.7 s, ~33.6 s.

Use an implemented method for a lookup, a validation, or a computation you already know is fast.
Anything that talks to a payment provider, waits on a human, or retries for a day belongs in a
workflow, which you declare with `#[FulfilsNexusOperation]`.

When you name a workflow, Durable starts it with the caller's callback attached, and the server
delivers that workflow's result to the caller when it finishes. Your handler is not called again.

### Cancellation

If the caller cancels, Durable cancels the workflow fulfilling the operation. You write no
cancellation hook: your workflow already observes cancellation and compensates, as described in
[Cancellation](../cancellation/).

A cancellation reaches a handler only for an operation that has **started**. For an operation still
waiting for its first answer, there is nothing to cancel on your side.

### Failing an operation {#failing}

To fail the operation, throw an exception:

```php
throw new \RuntimeException('the payment provider is unreachable');
```

An ordinary exception is reported as `INTERNAL`, which is **retryable**: the task comes back, up to
the operation's budget. That suits an outage. It does not suit a bad request, which no retry can
fix. For a terminal failure, state its kind:

| terminal, do not retry | retryable, try again |
|---|---|
| `BAD_REQUEST`, `UNAUTHENTICATED`, `UNAUTHORIZED` | `RESOURCE_EXHAUSTED`, `INTERNAL` |
| `NOT_FOUND`, `NOT_IMPLEMENTED`, `CONFLICT` | `UNAVAILABLE`, `UPSTREAM_TIMEOUT`, `REQUEST_TIMEOUT` |

The two columns split by *whose fault it is*. Retrying does not fix a malformed request or a
missing right; it may get past an overload or an upstream timeout. The table comes from nexus-rpc
and every language SDK shares it.

An operation nobody serves gets `NOT_IMPLEMENTED`, which is terminal, and the worker keeps serving
its other operations.

---

## Running the worker

Serving needs a worker (the process that pulls work and serves Nexus operations; see the
[glossary](../glossary/)) on the Nexus task queue. The bundle registers it, like the workflow and
activity workers, as soon as a handler is declared, and you add nothing to `messenger.yaml`:

```bash
php bin/console messenger:consume durable_nexus --time-limit=3600
```

The queue comes from the DSN. `nexus_task_queue` sets it; **it defaults to the workflow task
queue**, because a Nexus endpoint targets a queue and the server only delivers to a queue that a
worker polls. If no worker polls the queue, the endpoint never answers and no error appears
anywhere.

---

## Registering the endpoint

An endpoint is a cluster-wide object. An operator creates it once; the application does not:

```bash
temporal operator nexus endpoint create \
    --name payments \
    --target-namespace production \
    --target-task-queue durable-workflows
```

The `--target-task-queue` must be the queue your Nexus worker polls.

---

## If you declare a handler on the wrong backend

The container build fails, and the message names what is missing:

```
durable.nexus_handler: a Nexus handler is declared, but this backend cannot route
Nexus operations. Nexus needs the Temporal backend — set durable.temporal.dsn.
Declared by: app.charge.
```

The caller side behaves differently, by design. A call on a backend with no route fails at the
call, so you find out immediately. A *handler* with no route receives nothing, and nothing fails:
no request ever reaches it. On every host, the check therefore runs before any request:
when the container is built (Symfony), at boot (Laravel), and when the Nexus worker starts (Magento).

The message above is Symfony's. On Magento, `bin/magento durable:worker --role=nexus` fails with
`A Nexus worker needs a cluster` when `app/etc/env.php` has no DSN.
On Laravel, the provider fails at boot with `NexusUnsupportedByBackendException` when a handler is listed in
`durable.nexus.handlers` and the backend is not `temporal`. The message names the backend.

---

## A demonstration with four applications {#four-applications-for-real}

The repository ships a demonstration where four Durable applications call each other, across three
frameworks.

| | `sylius/`, the shop | `symfony/`, the back office | `magento/`, the Magento bench | `laravel/`, the logistics |
|---|---|---|---|---|
| namespace | `demo-shop` | `demo-business` | `demo-magento` | `demo-laravel` |
| serves | `stock` (`reserve`) | `billing` (`verify`, `charge`) | **nothing** | `delivery` (`schedule`, `ship`) |
| calls | `billing` | `stock` | all three services | `stock`, **from the workflow that serves** |
| what declares the handler | a tag under `when@demo` | `#[AsNexusServiceHandler]` | nothing | six lines of `config/durable.php` |

All four read the same contract package. Nothing else travels between them.

The Magento bench serves nothing, but the Magento module can serve operations: the handlers listed in the `nexusHandlers` argument of `RuntimeFactory`, as in
[Serving an operation](#serving-an-operation).

The shop's order workflow calls both forms on the same stub:

```php
$verdict = $this->environment->await($this->billing->verify($order, $amount, $currency));

if (true !== ($verdict['accepted'] ?? false)) {
    return ['verified' => $verdict, 'charge' => null];
}

return [
    'verified' => $verdict,
    'charge' => $this->environment->await($this->billing->charge($order, $amount, $currency)),
];
```

`verify` is answered by a method the back office wrote. `charge` has no handler body at all: a
workflow claims it, sleeps twelve seconds, calls a payment activity, and its result becomes the
operation's. **Nothing in the code above distinguishes the two.** The caller's history shows the
difference:

```
 5  NexusOperationScheduled     verify
 6  NexusOperationCompleted     verify    ← same second
10  NexusOperationScheduled     charge
11  NexusOperationStarted       charge    ← a workflow took it
15  NexusOperationCompleted     charge    ← fourteen seconds later
19  WorkflowExecutionCompleted
```

During one run, the worker that advances the fulfilling workflow stayed **off for four minutes**.
The operation stayed at `NexusOperationStarted`, the caller consumed nothing, and everything
finished normally when the worker came back.

### Calling asks nothing of your host

The third application separates what Nexus requires from the framework from what it requires from
you. The first two are both Symfony: they share its container, the compile pass that registers
handlers, and the Messenger transport that runs the workers. From those two alone, all of that
could pass for a feature of the bundle.

The Magento bench has none of it. It wires services in `di.xml`, runs its worker with
`bin/magento durable:worker --role=journal`, and reads its DSN from `app/etc/env.php`. It calls all
three services, the immediate ones and the two a workflow fulfils, and this required **no change to
the core, to the Temporal bridge or to `gplanchat/durable-magento`**.

The two sides are not symmetrical:

- **Calling** needs a workflow whose journal is the cluster, and nothing else.
  `WorkflowEnvironment::nexusStub()` reads the contract by reflection; no container is involved.
- **Serving** needs the host to register handlers and to poll a Nexus task queue. That is host work,
  written once per host: a compile pass in Symfony, a config file in Laravel, a `di.xml` argument in
  Magento.

The cluster shows this asymmetry: four namespaces, **three endpoints**. An endpoint says where a
service is served, so an application that only calls has none.

```php
// The Magento bench, calling three services from one workflow. This is the whole of the host
// integration: three stubs and five awaited operations.
$verdict = $this->environment->await($this->billing->verify($order, $amount, $currency));
$delivery = $this->environment->await($this->delivery->schedule($order, $lines));
$reservation = $this->environment->await($this->stock->reserve($order, $lines));
$receipt = $this->environment->await($this->billing->charge($order, $amount, $currency));
$shipment = $this->environment->await($this->delivery->ship($order, $delivery['slot']));
```

> [!WARNING]
> **The order of those five calls matters.** Two inverted orders were written first, and both were
> measured: an order in USD reserved the stock and *then* had its invoice refused, and an order of
> six parcels was *charged* before the logistics refused to carry it. None of the three contracts
> has an operation that gives back what it took. **Call every operation that can say no first, and
> commit afterwards.** When an operation has no compensating counterpart, the order of the calls
> takes the place of compensation.

### Serving on a host other than Symfony {#serving-is-host-work-and-it-is-not-symfony-work}

The other half of the asymmetry has its own demonstration. Before the Laravel bench, every served
operation was registered by a Symfony compile pass and polled by a Symfony transport. Here is the
whole of the host wiring on a framework that has neither:

```php
// config/durable.php
'backend' => env('DURABLE_BACKEND', 'temporal'),   // serving Nexus needs the cluster: it routes
'temporal' => ['dsn' => env('DURABLE_DSN')],
'workflows' => [App\Durable\Workflow\ShipWorkflow::class],
'nexus' => ['handlers' => [
    App\Durable\Nexus\DeliveryHandler::class => DeliveryContract::class,
]],
```

`DeclaredNexusOperations` reads that file the way `NexusHandlerPass` reads Symfony's tags, through
the same `NexusContractResolver` and the same `NexusHandlerInvoker`; `php artisan durable:nexus-worker`
polls the queue. The handler class contains none of this: it implements `DeliveryServed` and does
not mention Nexus. Magento reads its `nexusHandlers` argument through the same core class as
Laravel, `NexusHandlerDeclarations`, and `bin/magento durable:worker --role=nexus` polls the queue.

> [!WARNING]
> **The signature check lives in the core, shared by every host.** Registration fails for a
> fulfilling workflow whose required parameter matches nothing in the contract's signature, and
> the message names both signatures. The payload is keyed by parameter name at both ends, so
> without that check the parameter would receive `null`. Symfony calls the check from its compile
> pass, Laravel from `durable.nexus.handlers`, Magento from the `nexusHandlers` argument. It was
> written for the first host and moved into the core when a second host arrived.

### A workflow that serves can call

`ShipWorkflow` fulfils `delivery/ship`. Before releasing the goods it asks the shop for its
verdict again, through `stock/reserve`, on another application's endpoint. One execution (one
durable run of a workflow; see the [glossary](../glossary/)) therefore carries an operation it
serves and an operation it calls:

```
 5  TimerStarted              ← six seconds of picking
 6  TimerFired
10  NexusOperationScheduled   ← stock/reserve, at the shop
11  NexusOperationCompleted
15  WorkflowExecutionCompleted
```

Its workflow id is the **operation token** of the operation it fulfils; a workflow started by a
Nexus task is not named by the application that runs it.

The call is safe because `reserve` is idempotent per order id: the shop re-reads the decision it
made at order time instead of taking a new one, which is why the lines passed are empty.

Prerequisites, the processes to start and the commands to run are in
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md). Before you
start, note two points. A server that answers `Nexus APIs are disabled` does not work, and
`temporal server start-dev` does. The four applications do not run on the same PHP binary.

---

## See also

- [Backends](../backends/) says which backend can route Nexus, and why the others cannot.
- [Cancellation](../cancellation/) covers what your fulfilling workflow does when the caller cancels.
- [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md) is the decision record, and the measurements behind it.
