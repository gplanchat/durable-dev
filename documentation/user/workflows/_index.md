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

`WorkflowEnvironment` provides **`await`**, the assemblers **`all`** / **`any`** / **`some`**, **`async`**, timers, child workflows, signals, and more — see the class in the repository for the full API.

### Waiting versus assembling

**`await()` is the only method that waits.** Everything else assembles: a stub call, `timer()`,
Stub calls and the assemblers below all return an `Awaitable` and return
immediately.

```php
$env->sleep(Duration::minutes(5));            // wait, and nothing else — awaits for you

$winner = $env->await($env->any(              // assemble, then wait
    $activities->callProvider($orderId),
    $activities->callFallbackProvider($orderId),
));
```

Three assemblers, by how many members have to finish:

```php
$env->all($a, $b, $c)      // Awaitable of [$a, $b, $c] — every member, in declaration order
$env->any($a, $b, $c)      // Awaitable of the first member to settle, whatever its fate
$env->some(2, $a, $b, $c)  // Awaitable of the first 2 members to succeed, keyed by position
```

Because they return an `Awaitable` and not a value, they **compose**: an assembly nests in
another, and — the reason this matters most — an assembly can be bounded by a deadline.

```php
$quotes = $env->await($env->some(3, ...$providers), Duration::seconds(2));
```

`some()` counts only members that **succeed**: a provider that fails does not bring the quorum
closer, and once too few remain to reach it the wait fails rather than never settling. `all()` is
the full quorum, so one failed member fails the whole assembly. `any()` is a race, so the first
member to settle wins even by failing.

Losing branches are cancelled — activities removed from the queue, timers stopped from waking the
execution — including branches nested inside an assembly.

`timer()` returns an `Awaitable` exactly like a stub call, so both compose the same way. Both
accept a `Duration`, a `DateInterval` (so a `CarbonInterval`), a `DateTimeInterface` deadline, or a
plain number of seconds.

### Bounding a wait in time

To give up on a wait after a while, pass a **deadline** to `await()` — do not race a timer by
hand. `any()` resolves to the winning **value** and nothing else, so a provider that legitimately
answers `null` is indistinguishable from an elapsed deadline; a saga that compensates on timeout
would compensate on an empty answer too.

```php
use Gplanchat\Durable\Exception\DeadlineExceededException;

try {
    $quote = $env->await($activities->callProvider($orderId), Duration::seconds(30));
} catch (DeadlineExceededException $e) {
    // The provider did not answer in time — compensation path.
    // $e->deadline() is the deadline that elapsed, $e->awaited() what it was bounding.
}
```

The deadline defaults to `Duration::infinity()` — an unbounded wait says so with a value rather
than with a missing argument, so a caller that computes its own deadline has no "no bound" case to
special-case.

A deadline is a failure, not a sentinel: `null`, `false` and `[]` come back untouched when the
work settles in time.

### Waiting on a condition

`await()` also takes a **condition** — a predicate over the workflow's own state — wherever it
takes an awaitable, with the same optional deadline. That is what a signal handler wakes:

```php
$env->onSignal(OrderSignal::Approve, fn(array $p) => $this->approvals[] = $p);

try {
    $env->await(fn(): bool => [] !== $this->approvals, Duration::hours(1));
} catch (DeadlineExceededException) {
    return $this->expire($orderId);
}
```

That is the canonical saga shape: wait for approval, give up after an hour.

A condition must be a function of **workflow state and nothing else**. It is re-evaluated on every
replay, so anything a replay cannot reproduce — a clock, a random draw, an environment variable —
must be recorded once with `sideEffect()` and read back:

```php
$threshold = $env->sideEffect(fn(): int => random_int(1, 10));   // recorded once
$env->await(fn(): bool => $this->received >= $threshold);        // replays identically
```

The component does **not** detect a condition that breaks this rule; it detects no other
non-determinism either, and `sideEffect()` is the mechanism it gives you instead.

> [!WARNING]
> `fn()` captures **by value**. A condition over a local variable must use the long form:
> `function () use (&$approvals): bool { … }`. Over `$this->property` the short form is fine —
> `$this` is captured, not the value.

A condition that can never hold — nothing pending can change the state it reads — is reported as an
execution that cannot advance, naming the condition by its file and line, rather than spinning.

Whichever branch loses is cancelled: a deadline that elapses cancels the work it bounded, and
work that settles cancels the deadline, so no dead timer wakes the execution later. Cancelling an
in-flight activity is **best effort** — Temporal receives a cancellation *request*, and an attempt
that does not honour it may keep running on its worker. What the deadline guarantees is that its
completion no longer resumes your workflow.

The verdict is read from recorded history, so a replay reaches the verdict the original execution
reached — **including** when the awaited signal is delivered after the deadline elapsed. A message
recorded after the deadline fired is never applied to the wait that deadline settled; it stays
available to the next wait, and its handler runs then. See **DUR032** and **DUR035**.

### ActivityOptions on the stub

To apply **retries**, **timeouts**, **task queue**, and related scheduling metadata to every call made through a given stub, pass **`ActivityOptions`** as the second argument to **`activityStub()`**:

```php
use Gplanchat\Durable\Activity\ActivityOptions;

$options = ActivityOptions::of(5, 120);   // 5 attempts, 120s each
$activities = $this->environment->activityStub(OrderActivities::class, $options);
```

More patterns are in [Creating activities — ActivityOptions](../activities/#activityoptions-timeouts-retries-task-queue),
and every option is described in [Options and value objects](../options/).

### Naming: ActivityStub vs ActivityInvoker

ADRs use the canonical term **`ActivityInvoker`** for this pattern. In the current package the type is **`ActivityStub`**, returned by **`WorkflowEnvironment::activityStub()`** — same role: typed calls that return **`Awaitable`**. The stub delegates to a narrow scheduling port that a workflow never receives — which is why naming an activity as a string is not something you can do from workflow code.

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

The engine injects **`WorkflowEnvironment`** into your constructor. This is its whole surface —
everything a workflow can do, and nothing the engine keeps for itself.

| | |
|---|---|
| `await($awaitable, $deadline = null)` | The only wait. An elapsed deadline raises `DeadlineExceededException` — a failure, not a value, so work that legitimately returns `null` stays distinguishable. |
| `all(...$awaitables)` | Settles when every member succeeds. One failure fails the whole. |
| `any(...$awaitables)` | Settles on the first member to settle; the losers are cancelled. |
| `some($count, ...$awaitables)` | Settles when `$count` members have **succeeded**, indexed by declaration position. The rest are cancelled. |
| `timer($duration, $summary = '')` | An awaitable that settles when the duration elapses. Composes like any other. |
| `sleep($duration, $summary = '')` | Waits, and awaits for you. Says what it does. |
| `activityStub($contract, $options = null)` | A typed proxy over an activity contract. Build it in the constructor; every call it makes carries `$options`. |
| `childWorkflowStub($class, $options = null)` | The same, for a child workflow: resolved from the child's class, and its calls compose like any other. |
| `waitSignal($name, $deadline = null)` | Waits for a signal. The name takes a backed enum, so a typo is a type error rather than a wait that never settles. |
| `waitUpdate($name)` | Waits for an update. |
| `sideEffect($closure)` | Runs non-deterministic local work once and journals its result, so replay reproduces it. |
| `continueAsNew($type, $payload = [], $options = null)` | Ends this run and starts the next with a fresh history. |
| `executionId()` | This execution's identifier. |

Activities are **only** reachable through a stub. Naming one as a string with a free-form payload
is not on this surface: a typo there produces an activity that is never scheduled, instead of an
error your IDE and your static analyser catch first.

Query, signal and update handlers are declared with `#[QueryMethod]`, `#[SignalMethod]` and
`#[UpdateMethod]`, and the engine wires them. They can also be registered imperatively —
`registerQueryHandler()`, `onSignal()`, `onUpdate()` — which is what a workflow expressed as a
closure has to use, since a closure cannot carry an attribute. Prefer the attribute: it is the
form a reader can see without running anything.

You never instantiate activity implementations inside the workflow body.

## Checklist

| Rule | Detail |
|------|--------|
| Constructor | Only `WorkflowEnvironment` |
| Contract | Interface + `#[Workflow]`; class implements it |
| Entry | At least one `#[WorkflowMethod]`; use `default: true` if multiple |
| I/O | None in the workflow — use activities |
| Calls to work | Through an **`ActivityStub`**, built in the constructor from an activity contract |

## See also

- [Concepts](../concepts/) — workflow vs activity, replay, backends.
- [Creating activities](../activities/) — activity interfaces, `#[ActivityMethod]`, and **`ActivityInvoker`**.
