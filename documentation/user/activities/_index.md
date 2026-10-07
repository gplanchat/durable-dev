---
title: Creating activities
weight: 30
---

# Creating activities

This page shows how to **write** an activity, a unit of side effect such as an HTTP call, a database write or an e-mail (see the [glossary](../glossary/)), and how to call it from a workflow. The normative detail is in [**DUR023**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR023-activity-authoring-and-asynchronous-activity-proxy.md) and [**DUR004**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR004-activity-stub-and-activities.md); this page covers the practical side.

## The contract interface and the implementation class {#two-pieces}

An activity has two parts.

1. **Activity contract interface.** It lists the methods the workflow may call, each marked with **`#[AsActivityMethod]`**. The workflow calls them through an **`ActivityStub`**, an object that exposes the contract's methods to the workflow (**ActivityInvoker** in the ADRs).
2. **Activity implementation class.** A concrete class carrying **`#[AsActivityHandler]`**, naming the contract it implements. On Symfony, that attribute is what registers the class: the bundle autoconfigures it, and without it the workflow finds no handler at run time. Laravel lists the class in `activity_handlers` in `config/durable.php`, where the attribute, if present, names the contract it serves; without it, the class serves its interfaces whose methods carry `#[AsActivityMethod]`. Magento lists it in the `activityHandlers` argument of `RuntimeFactory` in `di.xml`. Neither host scans attributes, so a class missing from the list serves nothing. See [who registers what, per host](../getting-started/#register-workflows-and-activities).

## Example: activity contract and implementation

The **interface** lists the methods the workflow can schedule. Each exposed method carries **`#[AsActivityMethod]`** with a **stable activity name** for the orchestrator. The **implementation** class performs the I/O and can use **constructor injection**.

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

Register **`OrderActivitiesHandler`** with your container or your activity worker (the process that runs activities), so the worker can execute **`charge-order`** when the workflow schedules it.

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
[`gplanchat/durable-phpstan`](https://github.com/gplanchat/durable-phpstan) reports a built stub
that could be an `#[Activities]` parameter, with the attribute to write, under the identifier
`durable.activityStubCouldBeParameter`.

The **`ActivityStub`** type resolves method names by reflection on **`OrderActivities`** and builds the **`#[AsActivityMethod]`** payloads. [Creating a workflow](../workflows/) explains the **ActivityInvoker** name.

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
> With no `RetryLimit`, attempts are **unlimited**, which is Temporal's default. An activity that
> always fails retries forever, and the workflow does not fail. Pass `RetryLimit::once()` when a
> failure must be final.

> [!NOTE]
> **Activity timeouts and deadlines.** `ActivityTimeouts` bounds one activity **attempt**, and the
> **backend** enforces it. It survives a worker crash and applies to that activity only. A
> **deadline** passed to `await()`, over an awaitable or a condition, is enforced **workflow-side**.
> It bounds *this* wait in *this* execution, and it also covers waits that activity timeouts do not
> cover, such as a child workflow, a signal or a composed group. Use `ActivityTimeouts` to bound a
> single attempt, and a deadline to bound any other wait. See
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

The journal, the record of an execution's steps and their results, prevents a **completed**
activity from running again. It does not cover an attempt that stops between its side effect and
the recording of its result. For example, the payment provider charges the card, then the attempt
times out or the worker dies. That attempt counts as failed, and it is retried. An activity runs
**at least once**.

Anything an activity does to the outside world therefore needs a key that stays the same on every
attempt. The workflow passes the same arguments to each attempt. Build the key from those arguments
and a fixed prefix that names the operation (`charge-`, `refund-`), never from a random value or the
current time:

```php
public function charge(string $orderId): string
{
    return $this->psp->charge($orderId, idempotencyKey: 'charge-' . $orderId);
}
```

The key is the same for every attempt of one operation, and different for two distinct
operations. `charge-<orderId>` fits only when an order is charged once. If the same order can be
charged again (a second instalment, a new execution for the same order), add the value that tells
the charges apart, such as the instalment number. Also check how long your provider keeps a key.

A `RetryLimit` bounds how many attempts reach the provider. It does not make a second attempt safe.
With `RetryLimit::once()`, a cut-off attempt is not retried. The call may or may not have
happened, and the workflow receives a failure.

## Dependency injection

Unlike a workflow, an **activity implementation** **can** have an ordinary constructor with **dependency injection**. It receives HTTP clients, databases, loggers and other services from the host of the **activity worker**, for example the Symfony container in the worker process.

### Heartbeats from a long-running activity {#heartbeats-a-long-activity-says-it-is-alive}

To report that a long activity is still alive, inject `ActivityHeartbeatSenderInterface` and call
`sendHeartbeat()` between steps. The call returns `true` once cancellation has been requested. When
it does, stop at that point and clean up.

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

On Temporal, whatever the host, the heartbeat resets the activity's `heartbeat` timeout (see
[ActivityTimeouts](../options/#activitytimeouts)) and carries the progress details. On the other
backends the call does nothing and never reports a cancellation, so the same code runs on every
backend.

## Workflow side: ActivityInvoker

On **`WorkflowEnvironment`** (see [Creating a workflow](../workflows/)), call **`activityStub(YourActivityInterface::class)`** to get an **`ActivityStub`**, the concept the ADRs call **`ActivityInvoker`**.

A stub that needs no **`ActivityOptions`** can also be declared as an argument of the workflow method: a parameter typed **`ActivityStub`** and marked **`#[Activities(YourActivityInterface::class)]`** receives the same stub. See [Arguments Durable supplies](../workflows/#arguments-durable-supplies).

- For each **`#[AsActivityMethod]`** on the interface, the stub exposes the **same method name and parameters**; each call returns an **`Awaitable`** you pass to **`$environment->await(...)`** (the synchronous return type **`T`** on the interface is what you get after **`await`**).
- The invoker **does not** run I/O inside the workflow process. It **schedules** a durable step and ties its result to the history and to replay (the re-execution of the workflow method from its first line, where recorded steps return their result).

This separation keeps the workflow code deterministic, while the activities do the non-deterministic work.

## Serialization

Arguments and return values cross the orchestrator boundary, so they must be **serializable** (**DUR007**). Do not pass raw resources, unsupported closures, or types that your configured serializer does not handle.

## Checklist

| Piece | Responsibility |
|-------|----------------|
| Interface | `#[AsActivityMethod]` on callable methods; serializable types |
| Implementation | I/O and DI; implements the interface |
| Workflow | Uses only **`activityStub()`** / **`ActivityStub`** from **`WorkflowEnvironment`**, or an **`#[Activities]`** argument; never instantiates the activity class with `new` for a durable effect; optional second argument **`ActivityOptions`** |

## See also

- [Creating a workflow](../workflows/) covers **`WorkflowEnvironment`** and **`ActivityInvoker`**.
- [Concepts](../concepts/) explains why activities own side effects and replay.
