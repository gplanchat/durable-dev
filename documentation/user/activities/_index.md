---
title: Creating activities
weight: 30
---

# Creating activities

This page summarizes how you **author** activities in Durable. Normative detail is in [**DUR023**](https://github.com/gplanchat/durable/blob/main/documentation/adr/DUR023-activity-authoring-and-asynchronous-activity-proxy.md) and [**DUR004**](https://github.com/gplanchat/durable/blob/main/documentation/adr/DUR004-activity-stub-and-activities.md); this guide stays practical.

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

Pass **`ActivityOptions`** as the **second argument** to **`activityStub()`**. All **`Awaitable`** instances returned by that stub use these settings when the activity is scheduled (retries, backoff, timeouts, optional task queue, activity id, UI summary, etc.). Timeouts are in **seconds** (fractional allowed).

### Using the constructor

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\ActivityOptions;

$options = new ActivityOptions(
    maxAttempts: 5,
    initialIntervalSeconds: 2.0,
    backoffCoefficient: 2.0,
    maximumIntervalSeconds: 60.0,
    startToCloseTimeoutSeconds: 120.0,
    summary: 'Charge order payment',
);

$activities = $this->environment->activityStub(OrderActivities::class, $options);

$result = $this->environment->await($activities->charge($orderId));
```

### Using `default()` and `with*()` builders

For small tweaks, start from **`ActivityOptions::default()`** and chain immutable setters:

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\ActivityOptions;

$options = ActivityOptions::default()
    ->withMaxAttempts(3)
    ->withStartToCloseTimeoutSeconds(90.0)
    ->withNonRetryableExceptions([\InvalidArgumentException::class]);

$activities = $this->environment->activityStub(OrderActivities::class, $options);
```

Create **separate stubs** when different calls need different policies (for example one stub with aggressive retries for flaky HTTP, another with stricter timeouts for a fast path).

### Low-level `activity()` call

If you schedule by **activity name** instead of a contract stub, **`WorkflowEnvironment::activity()`** also accepts an optional **`ActivityOptions`** argument — same metadata shape for the journal.

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
