---
title: Creating a workflow
weight: 25
---

# Creating a workflow

This page summarizes how you **author** a workflow in Durable. The normative rules live in contributor ADRs [**DUR022**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR022-workflow-class-interface-and-workflow-environment.md) and related decisions (**DUR003**, **DUR013**); this guide stays practical.

## Example: minimal workflow

Define a **contract interface** (optional but recommended for tests and typing) and a **concrete class** registered with the runtime. The **`#[Workflow]`** attribute is placed on the **class** in today’s loader (see [DUR022](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR022-workflow-class-interface-and-workflow-environment.md) for the long-term interface-first model).

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** Domain contract — no attributes required on the interface. */
interface OrderWorkflowContract
{
    public function run(string $orderId): mixed;
}

#[Workflow(name: 'order')]
final class OrderWorkflow implements OrderWorkflowContract
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(string $orderId): mixed
    {
        // Activity contract: see Creating activities. The stub schedules work; await runs it in the replay model.
        $activities = $this->environment->activityStub(OrderActivities::class);

        return $this->environment->await($activities->charge($orderId));
    }
}
```

`WorkflowEnvironment` provides **`await`**, **`all`**, **`any`**, **`race`**, **`parallel`**, **`async`**, timers, child workflows, signals, and more — see the class in the repository for the full API.

### ActivityOptions on the stub

To apply **retries**, **timeouts**, **task queue**, and related scheduling metadata to every call made through a given stub, pass **`ActivityOptions`** as the second argument to **`activityStub()`**:

```php
use Gplanchat\Durable\Activity\ActivityOptions;

$options = ActivityOptions::default()->withMaxAttempts(5)->withStartToCloseTimeoutSeconds(120.0);
$activities = $this->environment->activityStub(OrderActivities::class, $options);
```

More patterns (constructor, `withNonRetryableExceptions`, low-level **`activity()`**) are in [Creating activities — ActivityOptions](../activities/#activityoptions-timeouts-retries-task-queue).

### Naming: ActivityStub vs ActivityInvoker

ADRs use the canonical term **`ActivityInvoker`** for this pattern. In the current package the type is **`ActivityStub`**, returned by **`WorkflowEnvironment::activityStub()`** — same role: typed calls that return **`Awaitable`** and delegate to the single activity scheduling primitive.

## Example: two entry methods

If you expose **two** `#[WorkflowMethod]` methods on the same workflow type, **DUR022** requires **exactly one** to set **`default: true`** on the attribute. When the attribute exposes that parameter in your version, it looks like:

```php
#[WorkflowMethod]
public function runMain(Input $input): mixed { /* ... */ }

#[WorkflowMethod(default: true)] // illustrative — enable when supported by the attribute
public function runAlternate(Input $input): mixed { /* ... */ }
```

Until **`default`** exists on **`#[WorkflowMethod]`**, follow your runtime’s registration rules for which method is the primary entry.

## What you define

1. A **workflow interface** (optional contract) and/or a **class** annotated with **`#[Workflow]`** (attribute on the **class** with current loaders). It is the typed contract for registration and tests.
2. A **concrete class** that **implements** your contract and is registered with the runtime.
3. **Exactly one** constructor parameter on the implementation: **`WorkflowEnvironment $environment`**. Do **not** inject services, repositories, or other application dependencies into the workflow class—side effects belong in [activities](../activities/).

## Registry: alias and FQCN

When a workflow class is registered, the runtime indexes it under **two** strings: the **name** from **`#[Workflow]`** (first argument), or the class **short name** if that attribute is missing, and the **fully qualified class name (FQCN)**. **`WorkflowRegistry::getHandler()`** accepts **either** key for dispatch.

**Temporal and the durable journal** use the **alias** as the workflow type name (never the FQCN). **`WorkflowRunHandler`** and **`TemporalWorkflowStarter`** normalize **`WorkflowRunMessage`** payloads with **`WorkflowDefinitionLoader::aliasForTemporalInterop()`**: if you pass a FQCN, it is resolved to the alias before **`ExecutionStarted`** is persisted and before the Temporal **`WorkflowType`** is set. Stored metadata uses the alias for consistency with the server.

## Entry and optional handlers

- Declare **at least one** method with **`#[WorkflowMethod]`** — your main durable entry (scenario start).
- If you expose **several** `#[WorkflowMethod]` methods on the same workflow type, **exactly one** must set **`default: true`** so the runtime knows the primary entry.
- Optionally add:
  - **`#[SignalMethod]`** — external input that updates workflow state deterministically.
  - **`#[QueryMethod]`** — read-only view of state (no durable side effects from the handler).
  - **`#[UpdateMethod]`** — validated updates with response semantics when supported.

Parameters and return types must be **serializable** (see project serialization ADR **DUR007**).

## WorkflowEnvironment

The runtime injects **`WorkflowEnvironment`**. Use it to:

- Drive **replay-safe** async work: **`await`**, **`async`**, **`resolve`**, **`reject`**, **`all`**, **`race`**, **`any`** (exact semantics follow the library implementation).
- Obtain **`ActivityInvoker`** instances for your **activity interfaces** — you call **asynchronous** methods (`Awaitable<T>`) from the workflow; the real activity class runs on a worker with dependency injection.

You never instantiate activity implementations inside the workflow body.

## Checklist

| Rule | Detail |
|------|--------|
| Constructor | Only `WorkflowEnvironment` |
| Contract | Interface + `#[Workflow]`; class implements it |
| Entry | At least one `#[WorkflowMethod]`; use `default: true` if multiple |
| I/O | None in the workflow — use activities |
| Calls to work | Through **`ActivityInvoker`** from **`WorkflowEnvironment`** |

## See also

- [Concepts](../concepts/) — workflow vs activity, replay, backends.
- [Creating activities](../activities/) — activity interfaces, `#[ActivityMethod]`, and **`ActivityInvoker`**.
