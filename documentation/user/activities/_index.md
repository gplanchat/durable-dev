---
title: Creating activities
weight: 30
---

# Creating activities

This page summarizes how you **author** activities in Durable. Normative detail is in [**DUR023**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR023-activity-authoring-and-asynchronous-activity-proxy.md) and [**DUR004**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR004-activity-stub-and-activities.md); this guide stays practical.

## Two pieces

1. **Activity contract interface** — methods the workflow may call, each marked with **`#[ActivityMethod]`**. From the workflow you interact through **`ActivityStub`** (**ActivityInvoker** in ADRs).
2. **Activity implementation class** — concrete class (often annotated with **`#[Activity]`** for naming) that **implements** the contract and performs real work.

## Example: activity contract and implementation

The **interface** lists methods the workflow may schedule. Each exposed method carries **`#[ActivityMethod]`** with a **stable activity name** for the orchestrator. The **implementation** class performs I/O and may use **constructor injection**.

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Attribute\Activity;
use Gplanchat\Durable\Attribute\ActivityMethod;

interface OrderActivities
{
    #[ActivityMethod(name: 'charge-order')]
    public function charge(string $orderId): string; // synchronous return type on the worker
}

#[Activity(name: 'order-activities')]
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

From the workflow you never use **`OrderActivitiesHandler`** directly. You obtain a stub from **`WorkflowEnvironment`** and **`await`** the call (the stub returns an **`Awaitable`**).

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\WorkflowEnvironment;

// Inside a #[WorkflowMethod] on your workflow class:
$activities = $this->environment->activityStub(OrderActivities::class);

$receipt = $this->environment->await($activities->charge($orderId));
```

The **`ActivityStub`** type (see [Creating a workflow](../workflows/) for the **ActivityInvoker** naming note) resolves method names via reflection on **`OrderActivities`** and builds **`#[ActivityMethod]`** payloads.

## ActivityOptions (timeouts, retries, task queue)

Pass **`ActivityOptions`** as the **second argument** to **`activityStub()`**. Every **`Awaitable`** returned by that stub uses these settings when the activity is scheduled.

Retry limits and durations are **value objects**, not numbers — see [Options and value objects](../options/).

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\ActivityOptions;

// 5 attempts, 120s each, 2s before the first retry.
$options = ActivityOptions::of(
    5,
    120,
    2,
    [PaymentRefusedException::class],
    summary: 'Charge order payment',
);

$activities = $this->environment->activityStub(OrderActivities::class, $options);

$result = $this->environment->await($activities->charge($orderId));
```

> [!WARNING]
> With no `RetryLimit`, attempts are **unlimited** — Temporal's default. An activity that always
> fails retries forever instead of failing the workflow. Pass `RetryLimit::once()` when a failure
> should be final.

> [!NOTE]
> **Two timeouts, two owners.** `ActivityTimeouts` bounds an activity **attempt** and is enforced
> by the **backend**: it survives a worker crash, and it applies to that activity only. A
> **deadline** passed to `await()` — over an awaitable or a condition — is enforced
> **workflow-side**: it bounds
> *this* wait in *this* execution, and it covers what activity bounds cannot — a child workflow, a
> signal, a composed group. Reach for `ActivityTimeouts` to bound a single attempt, and for a
> deadline to bound anything else. See
> [Bounding a wait in time](../workflows/#bounding-a-wait-in-time).

Create **separate stubs** when different calls need different policies — one with aggressive
retries for a flaky HTTP call, another with stricter timeouts for a fast path:

```php
/** @var ActivityStub<SearchActivities> */
private readonly ActivityStub $flaky;

/** @var ActivityStub<PricingActivities> */
private readonly ActivityStub $strict;

// … dans le constructeur :
$this->flaky  = $env->activityStub(SearchActivities::class, ActivityOptions::of(
    10,
    initialInterval: Duration::milliseconds(200),
));

$this->strict = $env->activityStub(PricingActivities::class, ActivityOptions::of(
    RetryLimit::once(),
    timeouts: 2,
));
```

> [!NOTE]
> Declaring the property **`readonly`** is what lets
> [`gplanchat/durable-phpstan`](https://github.com/gplanchat/durable-phpstan) check the calls you
> make through the stub: PHPStan infers the contract from `activityStub()` and can follow it to
> the call site. A mutable property loses it, and then an explicit
> `/** @var ActivityStub<Contract> */` is needed. Either way, a contract it cannot resolve leaves
> the call unknown to the analyser — never silently accepted.


## Dependency injection

Unlike workflows, the **activity implementation** **may** use a normal constructor with **dependency injection**: HTTP clients, databases, loggers, etc., as provided by the **activity worker** host (for example the Symfony container in the worker process).

## Workflow side: ActivityInvoker

From **`WorkflowEnvironment`** (see [Creating a workflow](../workflows/)), you call **`activityStub(YourActivityInterface::class)`** and obtain an **`ActivityStub`** (same concept as **`ActivityInvoker`** in ADRs).

- For each **`#[ActivityMethod]`** on the interface, the stub exposes the **same method name and parameters**; each call returns an **`Awaitable`** you pass to **`$environment->await(...)`** (the synchronous return type **`T`** on the interface is what you get after **`await`**).
- The invoker **does not** run I/O inside the workflow process: it **schedules** a durable step and ties the result to history and replay.

This separation is what keeps workflow code deterministic while activities do blue-side (non-deterministic) work.

## Serialization

Arguments and return values must be **serializable** across the orchestrator boundary (**DUR007**). Avoid raw resources, unsupported closures, or types your configured serializer cannot handle.

## Checklist

| Piece | Responsibility |
|-------|----------------|
| Interface | `#[ActivityMethod]` on callable methods; serializable types |
| Implementation | I/O and DI; implements the interface |
| Workflow | Uses **`activityStub()`** / **`ActivityStub`** from **`WorkflowEnvironment`** only — never `new` the activity class for durable effects; optional second arg **`ActivityOptions`** |

## See also

- [Creating a workflow](../workflows/) — **`WorkflowEnvironment`** and **`ActivityInvoker`**.
- [Concepts](../concepts/) — why activities own side effects and replay.
