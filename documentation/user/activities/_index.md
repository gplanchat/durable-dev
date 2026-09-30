---
title: Creating activities
weight: 30
---

# Creating activities

This page summarizes how you **author** activities in Durable. Normative detail is in [**DUR023**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR023-activity-authoring-and-asynchronous-activity-proxy.md) and [**DUR004**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR004-activity-stub-and-activities.md); this guide stays practical.

## Two pieces

1. **Activity contract interface.** Methods the workflow may call, each marked with **`#[AsActivityMethod]`**. From the workflow you interact through **`ActivityStub`** (**ActivityInvoker** in ADRs).
2. **Activity implementation class.** A concrete class carrying **`#[AsActivityHandler]`**, naming the contract it implements. On Symfony, that attribute is what registers the class: the bundle autoconfigures it, and without it the workflow finds no handler at run time. Laravel lists the class in `activity_handlers` in `config/durable.php`, where the attribute, if present, names the contract it serves; without it, the class serves its interfaces whose methods carry `#[AsActivityMethod]`. Magento lists it in the `activityHandlers` argument of `RuntimeFactory` in `di.xml`. Neither scans attributes: an unlisted class serves nothing. See [who registers what, per host](../getting-started/#register-workflows-and-activities).

## Example: activity contract and implementation

The **interface** lists methods the workflow may schedule. Each exposed method carries **`#[AsActivityMethod]`** with a **stable activity name** for the orchestrator. The **implementation** class performs I/O and may use **constructor injection**.

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityHandler;
use Gplanchat\Durable\Attribute\AsActivityMethod;

// `#[AsActivity]` is optional and belongs on the **contract**: it prefixes the names of the
// activities declared below. On an implementation class nothing reads it.
#[AsActivity(name: 'order-activities')]
interface OrderActivities
{
    #[AsActivityMethod(name: 'charge-order')]
    public function charge(string $orderId): string; // synchronous return type on the worker
}

#[AsActivityHandler(contract: OrderActivities::class)]
final class OrderActivitiesHandler implements OrderActivities
{
    public function __construct(
        private readonly PaymentGatewayClient $payments,
    ) {
    }

    public function charge(string $orderId): string
    {
        return $this->payments->capture($orderId);
    }
}
```

Register **`OrderActivitiesHandler`** with your activity worker / container so the worker can execute **`charge-order`** when the workflow schedules it.

## Example: calling an activity from a workflow

From the workflow you never use **`OrderActivitiesHandler`** directly. Declare a stub of the
contract as a parameter of the workflow method, and **`await`** each call. A call on the stub
returns an **`Awaitable`**. Durable builds the stub and passes it in; the caller that starts the
workflow never passes it.

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** @param ActivityStub<OrderActivities> $activities */
#[AsWorkflowMethod]
public function run(
    string $orderId,
    #[Activities(OrderActivities::class)]
    ActivityStub $activities,
    WorkflowEnvironment $env,
): string {
    return $env->await($activities->charge($orderId));
}
```

Some workflows build the stub themselves with `$env->activityStub(OrderActivities::class)`: see
[When to build the stub yourself](../workflows/#when-to-build-the-stub-yourself).

The **`ActivityStub`** type (see [Creating a workflow](../workflows/) for the **ActivityInvoker** naming note) resolves method names via reflection on **`OrderActivities`** and builds **`#[AsActivityMethod]`** payloads.

## ActivityOptions (timeouts, retries, task queue)

Give the options to **`#[Activities]`**. Every **`Awaitable`** returned by that stub uses them
when the activity is scheduled. An attribute argument cannot call a constructor, so durations are
given in seconds:

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** @param ActivityStub<OrderActivities> $activities */
#[AsWorkflowMethod]
public function run(
    string $orderId,
    // 5 attempts, 120s each, 2s before the first retry.
    #[Activities(
        OrderActivities::class,
        attempts: 5,
        startToClose: 120.0,
        initialInterval: 2.0,
        nonRetryable: [PaymentRefusedException::class],
        summary: 'Charge order payment',
    )]
    ActivityStub $activities,
    WorkflowEnvironment $env,
): string {
    return $env->await($activities->charge($orderId));
}
```

When the options depend on the workflow's input, build them as an **`ActivityOptions`** value
object and pass it as the second argument of `$env->activityStub()`. Retry limits and durations are
then **value objects**, not numbers; see [Options and value objects](../options/).

> [!WARNING]
> With no `RetryLimit`, attempts are **unlimited**, which is Temporal's default. An activity that always
> fails retries forever instead of failing the workflow. Pass `RetryLimit::once()` when a failure
> should be final.

> [!NOTE]
> **Two timeouts, two owners.** `ActivityTimeouts` bounds an activity **attempt** and is enforced
> by the **backend**: it survives a worker crash, and it applies to that activity only. A
> **deadline** passed to `await()`, over an awaitable or a condition, is enforced
> **workflow-side**: it bounds
> *this* wait in *this* execution, and it covers what activity bounds cannot: a child workflow, a
> signal, a composed group. Reach for `ActivityTimeouts` to bound a single attempt, and for a
> deadline to bound anything else. See
> [Bounding a wait in time](../workflows/#bounding-a-wait-in-time).

Declare **separate stubs** when different calls need different policies: one with aggressive
retries for a flaky HTTP call, another with stricter timeouts for a fast path:

```php
/**
 * @param ActivityStub<SearchActivities>  $flaky
 * @param ActivityStub<PricingActivities> $strict
 */
#[AsWorkflowMethod]
public function run(
    string $query,
    #[Activities(SearchActivities::class, attempts: 10, initialInterval: 0.2)]
    ActivityStub $flaky,
    #[Activities(PricingActivities::class, attempts: 1, startToClose: 2.0)]
    ActivityStub $strict,
    WorkflowEnvironment $env,
): array {
    // ...
}
```

> [!NOTE]
> The **`@param ActivityStub<Contract>`** docblock is what lets
> [`gplanchat/durable-phpstan`](https://github.com/gplanchat/durable-phpstan) check the calls you
> make through the stub. PHP has no runtime generics, so the attribute names the contract for
> Durable and the docblock names it for PHPStan; the extension reports a docblock that names
> another contract than the attribute. A stub you build yourself into a **`readonly`** property
> needs no docblock: PHPStan infers the contract from `activityStub()`. Either way, a contract it
> cannot resolve leaves the call unknown to the analyser, never silently accepted.


## Idempotency

The journal keeps a **completed** activity from running again. It cannot do the same for an
attempt that stops between its side effect and the recording of its result: the payment provider
charged the card, then the attempt timed out or the worker died. That attempt failed, and it is
retried. An activity runs **at least once**.

Anything an activity does to the outside world therefore needs a key that is the same on every
attempt. The workflow passes the same arguments to each attempt, so build the key from them and a
fixed prefix naming the operation (`charge-`, `refund-`), never from a random value or the time:

```php
public function charge(string $orderId): string
{
    return $this->psp->charge($orderId, idempotencyKey: 'charge-' . $orderId);
}
```

The key is the same for every attempt of one operation, and different for two distinct
operations. `charge-<orderId>` is right only if an order is charged once. If the same order can be
charged again (a second instalment, a new execution for the same order), add what tells the charges
apart, such as the instalment number. Check also how long your provider remembers a key.

A `RetryLimit` bounds how many attempts reach the provider; it does not make the second one safe.
With `RetryLimit::once()`, a cut-off attempt is not retried: the call may or may not have happened,
and the workflow sees a failure.

## Dependency injection

Unlike workflows, the **activity implementation** **may** use a normal constructor with **dependency injection**: HTTP clients, databases, loggers, etc., as provided by the **activity worker** host (for example the Symfony container in the worker process).

### Heartbeats: a long activity says it is alive

A long activity injects `ActivityHeartbeatSenderInterface` and calls `sendHeartbeat()` between
steps. The call returns `true` once cancellation was requested: stop there and clean up.

```php
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;

final class ImportCatalog implements CatalogActivities
{
    public function __construct(
        private readonly ActivityHeartbeatSenderInterface $heartbeat,
        private readonly CatalogReader $reader,
    ) {}

    public function import(string $file): int
    {
        $count = 0;
        foreach ($this->reader->batches($file) as $batch) {
            $count += $this->reader->store($batch);
            if ($this->heartbeat->sendHeartbeat(['imported' => $count])) {
                break; // cancelled: stop between two batches, never in the middle of one
            }
        }

        return $count;
    }
}
```

On Temporal, on every host, the heartbeat resets the activity's `heartbeat` timeout (see
[ActivityTimeouts](../options/#activitytimeouts)) and carries the progress details. On the other
backends it is a no-op that never reports a cancellation, so the same code runs everywhere.

## Workflow side: ActivityInvoker

From **`WorkflowEnvironment`** (see [Creating a workflow](../workflows/)), you call **`activityStub(YourActivityInterface::class)`** and obtain an **`ActivityStub`** (same concept as **`ActivityInvoker`** in ADRs).

A stub that needs no **`ActivityOptions`** can also be declared as an argument of the workflow method: a parameter typed **`ActivityStub`** and marked **`#[Activities(YourActivityInterface::class)]`** receives the same stub. See [Arguments Durable supplies](../workflows/#arguments-durable-supplies).

- For each **`#[AsActivityMethod]`** on the interface, the stub exposes the **same method name and parameters**; each call returns an **`Awaitable`** you pass to **`$environment->await(...)`** (the synchronous return type **`T`** on the interface is what you get after **`await`**).
- The invoker **does not** run I/O inside the workflow process: it **schedules** a durable step and ties the result to history and replay.

This separation is what keeps workflow code deterministic while activities do blue-side (non-deterministic) work.

## Serialization

Arguments and return values must be **serializable** across the orchestrator boundary (**DUR007**). Avoid raw resources, unsupported closures, or types your configured serializer cannot handle.

## Checklist

| Piece | Responsibility |
|-------|----------------|
| Interface | `#[AsActivityMethod]` on callable methods; serializable types |
| Implementation | I/O and DI; implements the interface |
| Workflow | Uses **`activityStub()`** / **`ActivityStub`** from **`WorkflowEnvironment`** or an **`#[Activities]`** argument only; never `new` the activity class for durable effects; optional second arg **`ActivityOptions`** |

## See also

- [Creating a workflow](../workflows/) covers **`WorkflowEnvironment`** and **`ActivityInvoker`**.
- [Concepts](../concepts/) explains why activities own side effects and replay.
