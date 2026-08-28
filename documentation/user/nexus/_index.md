---
title: Nexus operations
weight: 29
---

# Nexus operations

Nexus lets a workflow call an operation owned by another team, another namespace, another
deployment — without either side knowing the other's workflows. Durable does both roles: it
**calls** operations, and it **serves** them.

Serving requires the **Temporal backend**. The in-memory and DBAL backends have no cross-namespace
route, and they say so rather than pretending — see [Backends](../backends/).

---

## Calling an operation

```php
#[AsNexusService('billing')]
interface BillingContract
{
    #[AsNexusOperation('charge')]
    public function charge(Order $order, int $amount): Receipt;
}
```

```php
$billing = $env->nexusStub(BillingContract::class, endpoint: 'payments');

$receipt = $env->await($billing->charge($order, 1200));
```

The contract is written **once** and read from both sides of the boundary: the caller derives a
typed stub from it, the handler implements it. No operation name is retyped as a string, so a typo
is a type error rather than an operation waiting for a handler whose name will never match.

The endpoint is a parameter of the stub, not of the contract: it says *where* the service is served,
which is a deployment concern and changes between environments, while the contract does not.

`nexusStub()` assembles; `await()` waits. Same rule as everywhere else — see
[Creating a workflow](../workflows/).

The payload travels **as you wrote it**. There is no Durable envelope around it, so a handler
written with the Go, Java or TypeScript SDK reads the fields it declares.

Whether the handler answers immediately or hours later changes nothing here: the workflow waits on
the operation, and the result arrives when it arrives.

---

## Serving an operation

A handler implements the contract — or the part of it that it answers immediately:

```php
use Gplanchat\Durable\Attribute\AsNexusServiceHandler;

#[AsNexusServiceHandler(contract: BillingServed::class)]
final class Billing implements BillingServed
{
    public function verify(Order $order): Verdict
    {
        return $this->rules->check($order);
    }
}
```

### Why the contract comes in two pieces

An operation fulfilled by a workflow has no handler body — the plumbing starts the workflow, and the
server delivers its result. So the contract splits: the interface a handler **implements**, and the
one that **extends** it for the caller.

```php
#[AsNexusService('billing')]
interface BillingServed                        // answered immediately
{
    #[AsNexusOperation('verify')]
    public function verify(Order $order): Verdict;
}

#[AsNexusService('billing')]
interface BillingContract extends BillingServed // + what a workflow fulfils
{
    #[AsNexusOperation('charge')]
    public function charge(Order $order, int $amount): Receipt;
}

#[AsWorkflow]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class Charge { /* … */ }
```

Without the split, PHP would demand a body for `charge()` on the handler — an empty method whose only
job is to say there is nothing to write. The workflow claims the operation instead, where its code
actually lives, and the caller's contract still declares everything so the stub can call it all.

### Answering now, or answering later

There are two forms, and choosing between them is the one decision that matters.

```php
// Now — the handler returns the contract's own type.
public function verify(Order $order): Verdict { … }

// Later — a workflow claims the operation, and produces the result.
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class Charge { … }
```

**A handler has roughly nine seconds.** That is not the operation's budget, it is the budget for
answering *this task*: the caller's `scheduleToClose` may be five minutes, but the task itself
carries a `request-timeout` of about nine seconds. A handler still working when it expires has its
task redelivered — and starts over. Measured redeliveries: ~9.9 s, ~20.7 s, ~33.6 s.

So an implemented method is for a lookup, a validation, a computation you already know is fast.
Anything that talks to a payment provider, waits on a human, or retries for a day belongs in a
workflow — and that is what `#[FulfilsNexusOperation]` declares.

When you name a workflow, Durable starts it with the caller's callback attached, and the server
delivers that workflow's result to the caller when it finishes. Your handler is not called again.

### Cancellation

If the caller cancels, Durable cancels the workflow fulfilling the operation. You do not write a
cancellation hook: your workflow already observes cancellation, and compensates, exactly as
described in [Cancellation](../cancellation/).

A cancellation only reaches a handler for an operation that has **started** — an operation still
waiting for its first answer has nothing to cancel on your side.

### Failing

Raise, and the operation fails:

```php
throw new \RuntimeException('the payment provider is unreachable');
```

An ordinary exception is reported as `INTERNAL`, which is **retryable** — the task comes back, up to
the operation's budget. That is right for an outage and wrong for a bad request, which will never
improve. For a terminal refusal, say which kind it is:

| terminal — do not retry | retryable — try again |
|---|---|
| `BAD_REQUEST`, `UNAUTHENTICATED`, `UNAUTHORIZED` | `RESOURCE_EXHAUSTED`, `INTERNAL` |
| `NOT_FOUND`, `NOT_IMPLEMENTED`, `CONFLICT` | `UNAVAILABLE`, `UPSTREAM_TIMEOUT`, `REQUEST_TIMEOUT` |

The line is *whose fault is it*. A malformed request or a missing right will not be fixed by
retrying; an overload or an upstream timeout might. The table is nexus-rpc's, shared by every
language SDK — not a Durable invention.

An operation nobody serves is answered `NOT_IMPLEMENTED`, terminal, and the worker keeps serving
its other operations.

---

## Running the worker

Serving needs a worker on the Nexus task queue. It is a Messenger transport, like the activity
worker:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            durable_temporal_nexus:
                dsn: '%env(DURABLE_DSN)%'
                options:
                    purpose: nexus_worker
```

```bash
php bin/console messenger:consume durable_temporal_nexus --time-limit=3600
```

The queue comes from the DSN. `nexus_task_queue` sets it; **it defaults to the workflow task
queue**, because a Nexus endpoint targets a queue and the server only delivers where someone polls.
A queue nobody serves is an endpoint that never answers, without an error anywhere.

---

## Registering the endpoint

An endpoint is a cluster-wide object, created once by an operator, not by the application:

```bash
temporal operator nexus endpoint create \
    --name paiements \
    --target-namespace production \
    --target-task-queue durable-workflows
```

The `--target-task-queue` must be the queue your Nexus worker polls.

---

## If you declare a handler on the wrong backend

The container refuses to build, and names what is missing:

```
durable.nexus_handler: a Nexus handler is declared, but this backend cannot route
Nexus operations. Nexus needs the Temporal backend — set durable.temporal.dsn.
Declared by: app.encaisser.
```

This is deliberate, and it is not how the caller side behaves. A call on a backend with no route
fails at the call — you find out immediately. A *handler* with no route is not a call that fails, it
is a service that never receives anything, silently. There is no request left to fail, so the
refusal happens when the application starts.

---

---

## A complete example, both sides

Two teams. **Billing** serves the operations; **Checkout** calls them. Neither knows the other's
workflows — that is what Nexus is for. Every class below is complete.

### The contract, shared by both sides

One file, published as a small library both teams depend on. It is the only thing they share.

```php
<?php

declare(strict_types=1);

namespace Acme\Billing\Contract;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/** What Billing answers immediately. A handler implements this one. */
#[AsNexusService('billing')]
interface BillingServed
{
    #[AsNexusOperation('quote')]
    public function quote(string $sku, int $quantity): int;
}

/** Everything Billing serves. A caller reads this one. */
#[AsNexusService('billing')]
interface BillingContract extends BillingServed
{
    #[AsNexusOperation('charge')]
    public function charge(string $orderId, int $amountInCents): string;
}
```

`charge` has no body anywhere: a workflow fulfils it. `quote` is a lookup, so it is implemented.
That split is why neither interface ever needs an empty method.

### Billing — the handler

```php
<?php

declare(strict_types=1);

namespace Acme\Billing\Nexus;

use Acme\Billing\Contract\BillingServed;
use Acme\Billing\PriceList;
use Gplanchat\Durable\Attribute\AsNexusServiceHandler;

#[AsNexusServiceHandler(contract: BillingServed::class)]
final class BillingHandler implements BillingServed
{
    public function __construct(
        private readonly PriceList $prices,
    ) {}

    /**
     * Answers on the task itself, so it must return in well under nine seconds.
     * A price lookup does; a payment provider would not.
     */
    public function quote(string $sku, int $quantity): int
    {
        return $this->prices->unitPriceOf($sku) * $quantity;
    }
}
```

### Billing — the workflow that fulfils `charge`

```php
<?php

declare(strict_types=1);

namespace Acme\Billing\Workflow;

use Acme\Billing\Contract\BillingContract;
use Acme\Billing\Contract\PaymentActivities;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('Charge')]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class ChargeWorkflow
{
    /** @var ActivityStub<PaymentActivities> */
    private readonly ActivityStub $payments;

    public function __construct(
        private readonly WorkflowEnvironment $env,
    ) {
        $this->payments = $env->activityStub(PaymentActivities::class);
    }

    /**
     * The caller's payload arrives as this method's arguments, and what this
     * method returns becomes the operation's result — however long it takes.
     */
    #[AsWorkflowMethod]
    public function run(string $orderId, int $amountInCents): string
    {
        $authorisation = $this->env->await(
            $this->payments->authorise($orderId, $amountInCents),
        );

        // Hours may pass here. The caller is not holding anything open.
        $this->env->await($this->env->timer(Duration::hours(2)));

        return $this->env->await($this->payments->capture($authorisation));
    }
}
```

### Checkout — the caller

```php
<?php

declare(strict_types=1);

namespace Acme\Checkout\Workflow;

use Acme\Billing\Contract\BillingContract;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('Checkout')]
final class CheckoutWorkflow
{
    /** @var NexusStub<BillingContract> */
    private readonly NexusStub $billing;

    public function __construct(
        private readonly WorkflowEnvironment $env,
    ) {
        $this->billing = $env->nexusStub(
            BillingContract::class,
            endpoint: 'billing-endpoint',
            timeouts: new NexusOperationTimeouts(scheduleToClose: Duration::hours(6)),
        );
    }

    #[AsWorkflowMethod]
    public function run(string $orderId, string $sku, int $quantity): string
    {
        // Immediate: Billing implements this one.
        $total = $this->env->await($this->billing->quote($sku, $quantity));

        // Deferred: a Billing workflow produces this one, hours later.
        // Nothing here says which is which — and nothing has to.
        return $this->env->await($this->billing->charge($orderId, $total));
    }
}
```

**The caller cannot tell the two apart, and that is the point.** Whether Billing answers on the task
or hands the work to a workflow is Billing's decision, changeable without touching Checkout.

> **The parameter names must match.** The caller's stub builds the payload from the *contract
> method's* parameter names — `charge(string $orderId, int $amountInCents)` sends
> `{"orderId": …, "amountInCents": …}` — and the fulfilling workflow's method is filled by the same
> names. Rename one side only and the workflow receives `null`, silently, because a missing key is
> indistinguishable from an argument that was not sent. The contract is what keeps the two honest:
> change it once, and both sides fail to compile rather than fail at runtime.

### Running it

Billing needs the Nexus worker consuming its queue:

```bash
php bin/console messenger:consume durable_temporal_nexus --time-limit=3600
```

And an operator creates the endpoint once, pointing at the queue that worker polls:

```bash
temporal operator nexus endpoint create \
    --name billing-endpoint \
    --target-namespace billing-prod \
    --target-task-queue durable-workflows
```

---

## See also

- [Backends](../backends/) — which backend can route Nexus, and why the others refuse.
- [Cancellation](../cancellation/) — what your fulfilling workflow does when the caller cancels.
- [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md) — the decision record, and the measurements behind it.
