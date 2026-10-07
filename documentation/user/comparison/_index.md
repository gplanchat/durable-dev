---
title: Durable and the Temporal PHP SDK
weight: 18
---

# Durable and the Temporal PHP SDK

Temporal ships an [official PHP SDK](https://github.com/temporalio/sdk-php). Durable does not
depend on it, and is neither a fork of it nor a wrapper around it: `composer.lock` contains no
`temporal/sdk` and no RoadRunner package. Both solve the same problem, durable execution of
long-running business logic, and they make different trade-offs at every layer below that.

This page describes those differences, including the ones where the SDK is ahead. It uses a few
Durable terms: a workflow is the PHP class that describes an execution's steps, an activity is a
unit of side effect it schedules, the journal is the append-only record of everything an execution
decided and received, a backend is where that journal lives, and a worker is the process that pulls
the work. The [glossary](../glossary/) defines each of them.

**Which SDK version.** Every claim below was checked against `temporal/sdk` **v2.18**, released
2026-08-17. The SDK keeps moving, and its maintainers have said publicly that they plan to close two
of the differences described here; sections [5](#5-fibers-or-generators-the-colouring-problem) and
[8](#8-nexus-the-one-place-durable-is-ahead) link to that public work. Each difference holds for
that version and may change in a later one.

---

## 1. The worker runtime: no RoadRunner

The SDK splits into a **client** and a **worker**. The client needs `ext-grpc`. The worker needs
**RoadRunner**, a Go application server that you download into the project with
`./vendor/bin/rr get` and configure through its own `.rr.yaml`. Workflow and activity code runs
inside PHP processes that RoadRunner supervises.

Durable has no second runtime. A worker is an ordinary PHP CLI process, started by the console your
host framework already ships. The host's own transport carries the work: Symfony Messenger,
Laravel's queue, or, on Magento, the backend queue that the command polls itself:

```bash
bin/console messenger:consume durable_workflows durable_activities  # Symfony
php artisan queue:work                                              # Laravel
bin/magento durable:worker --role=journal                           # Magento
bin/magento durable:worker --role=activity                          #   (two roles, two processes)
```

| | Durable | Temporal PHP SDK |
|---|---|---|
| Worker process | `messenger:consume`, `queue:work` or `bin/magento durable:worker`, supervised by whatever already supervises your processes | RoadRunner (Go binary), supervised by RoadRunner |
| Extra binary in the image | no | yes |
| Worker configuration | `messenger.yaml`, `config/durable.php` or `di.xml` | `.rr.yaml` |
| Deployment model | the one your application already uses | a second process model to learn and operate |

### When Durable still needs gRPC {#what-this-does-not-claim}

Durable does not remove gRPC. When the backend is Temporal, the bridge (the package that connects
Durable to that backend) speaks gRPC to the cluster, and **`ext-grpc` is required**. The
`gplanchat/durable-bridge-temporal` package declares that requirement; the core package does not:

| Package | Requires |
|---|---|
| `gplanchat/durable` | `php >= 8.2`, `psr/cache`, nothing else |
| `gplanchat/durable-bridge-temporal` | `ext-grpc`, `grpc/grpc`, `google/protobuf`, `symfony/messenger` |
| `gplanchat/durable-bridge-dbal` | `doctrine/dbal`, `symfony/lock`, `symfony/messenger` |
| `gplanchat/durable-bridge-illuminate` | `illuminate/database`, `illuminate/contracts` |
| `gplanchat/durable-laravel` | `illuminate/support`, `illuminate/container`, no Symfony component |

Durable never needs RoadRunner, and needs `ext-grpc` only when you talk to a Temporal cluster. The
in-memory, DBAL and Illuminate backends need no PHP extension beyond a standard install.
[DUR006](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md)
records the rule behind this.

---

## 2. Testability

The two libraries differ most on testing. The difference follows from how a workflow reaches the
engine, so it is structural, and test tooling does not remove it.

### With Durable, the workflow runs in the test process

`DurableTestCase` wires the In-Memory backend and runs your production class:

```php
final class GreetWorkflowTest extends DurableTestCase
{
    public function testWorkflowGreetsCorrectly(): void
    {
        $greetSpy = ActivitySpy::returns('Hello, Alice!');
        $env = $this->createWorkflowTestEnvironment(['greet' => $greetSpy]);

        $result = $env->runWorkflowClass(GreetingWorkflow::class, ['name' => 'Alice'], 'exec-1');

        self::assertSame('Hello, Alice!', $result);
        $greetSpy->assertCalledWith(['name' => 'Alice']);
        $this->assertWorkflowCompleted('exec-1', 'Hello, Alice!');
        $this->assertActivityExecuted('exec-1', 'greet');
    }
}
```

This test needs no server, no binary, no extension and no Docker. [Testing workflows](../testing/)
covers the full toolkit.

### With the SDK, every workflow test is an integration test

The SDK's test environment starts a **Temporal test server** *and* a **RoadRunner worker** from a
PHPUnit bootstrap file:

```php
// bootstrap.php
$environment = Temporal\Testing\Environment::create();
$environment->start();
register_shutdown_function(fn () => $environment->stop());
```

The test then drives the workflow from outside, over gRPC, and observes it through the client:

```php
$this->activityMocks->expectCompletion('SimpleActivity.doSomething', 'world');
$workflow = $this->workflowClient->newWorkflowStub(SimpleWorkflow::class);
$run = $this->workflowClient->start($workflow, 'hello');
$this->assertSame('world', $run->getResult('string'));
```

The workflow never executes in the PHPUnit process. Activity mocks go through an out-of-process
channel: the test writes the expectation on one side, and the worker reads it on the other. The
setup is faithful, since it runs a real Temporal server, but it has no cheaper tier below it. To
assert that a `match` in your workflow picks the right branch, you pay for two binaries and a gRPC
round trip.

### Why a Durable workflow can run in the test process {#why-durable-can-do-this}

Three properties of the authoring surface make it possible. None of them is a test helper.

- **The environment is injected.** In the SDK, `Workflow::newActivityStub()` reads a static context
  bound to the running worker and throws `OutOfContextException` outside it. A Durable workflow
  receives `WorkflowEnvironment` through its **constructor**, so a test can build it like any other
  PHP object. No global state needs resetting between tests.
- **Fibers replace generators.** A workflow method returns its declared type. PHPUnit compares a
  value; it does not drive a generator or resolve a promise.
- **The test runs the production class.** `runWorkflowClass()` goes through the same constructor,
  the same attributes and the same `#[AsWorkflowMethod]`; see
  [DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md).

### What you can assert

Durable exposes the **event journal** to the test, in addition to the return value:

| `DurableTestCase` | `ActivitySpy` |
|---|---|
| `assertWorkflowCompleted()` | `ActivitySpy::returns()` / `throws()` / `returnsSequence()` |
| `assertWorkflowFailed($failureClass)` | `assertCalledWith()` / `assertFirstCallWith()` |
| `assertActivityExecuted()` | `assertCalledTimes()` / `assertCalledOnce()` / `assertNotCalled()` |
| `assertEventStoreContains($eventClass)` | `calls()` / `callCount()` |
| `countActivityExecutions()` | |

`countActivityExecutions()` proves that an activity was **not** re-run after a retry. A black-box
assertion on the result cannot see that.

For Symfony integration tests, `DurableBundleTestTrait` does the same inside `KernelTestCase` and
drains the Messenger transports until the run settles.

### The tier that does need a server

Durable's own integration suite runs against a **real Temporal server**. It needs `ext-grpc`, a
running `temporal server start-dev`, and PHP worker processes that the test case spawns:

```bash
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

PHPUnit skips the suite when `DURABLE_TEMPORAL_ADDRESS` is unset.

Both libraries have a server-backed tier. They differ in **which tests need it**. In Durable, this
tier proves that a real server accepts the bridge's commands: round trips, failure paths,
deadlines, updates, cron schedules, search attributes, Nexus. It is deliberately narrow and tests
the *bridge*. The unit tier, which needs no infrastructure, covers the business logic of your
workflows. With the SDK, the server-backed tier is the only tier.

| | Durable | Temporal PHP SDK |
|---|---|---|
| Unit tier (business logic) | PHPUnit, in-process, zero infrastructure | none; every workflow test is out-of-process |
| Server-backed tier | optional, scoped to wire and protocol parity | mandatory, for all workflow tests |
| What it needs | a Temporal dev server + `ext-grpc` | test server + RoadRunner |
| Runs in CI without Docker | the unit tier does | no |

### What a passing In-Memory test does not prove {#the-honest-cost}

A passing In-Memory test does not prove that Temporal behaves the same way. The risk is real, and
three measures manage it:

- [DUR018](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR018-temporal-event-parity-replay-and-slots.md)
  requires event and slot parity between In-Memory and Temporal;
- [DUR016](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR016-in-memory-backend-exception-rules.md)
  bounds what an In-Memory implementation may simplify, and requires a docblock that justifies each
  shortcut;
- the integration tier above is what actually checks it.

You keep time skipping. The In-Memory runner holds a virtual clock and advances it to the next
timer's due date, so `sleep(3600)` settles in a millisecond of real time. It skips only when
nothing else can progress: skipping while an activity could still complete would make the timer
win every `any(activity, timer)` race. See
[Testing workflows](../testing/#time-is-skipped-not-waited-for).

---

## 3. Backends: one, or four

| | Durable | Temporal PHP SDK |
|---|---|---|
| Execution backends | **four**, running the same workflow code | a Temporal cluster |
| Tests | In-Memory, no server | test server |
| Production without a cluster | **DBAL** or **Illuminate**, durable execution on one SQL database | not possible |

The four are In-Memory plus three bridges you choose between: Temporal, DBAL and Illuminate.

The two SQL backends ([DUR030](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md)
on Doctrine's connection, [DUR047](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR047-laravel-the-host-that-measured-before-it-wired.md)
on Laravel's) have no counterpart in the SDK. They keep the journal, workflow metadata and locks on
a single relational database, with no cluster and no `ext-grpc`. For an application that needs
durable execution without the operational surface of a Temporal deployment, this is often the
deciding difference, more than the worker runtime.

Switching backends is a configuration change, and the setting depends on the host. On Symfony,
`durable.backend` accepts three of the four (`in_memory`, `dbal`, `temporal`). Illuminate is bound
by `gplanchat/durable-laravel` through its own `config/durable.php`. In both cases the workflow
code stays the same. See [Backends](../backends/).

---

## 4. The authoring surface

Here is the same workflow written twice. It charges an order, waits an hour, then sends the
receipt.

**Durable**, with an injected environment, fibers and plain return types:

```php
#[AsWorkflow(name: 'order')]
final class OrderWorkflow implements OrderWorkflowContract
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): string
    {
        $activities = $this->environment->activityStub(OrderActivities::class);

        $charge = $this->environment->await($activities->charge($orderId));
        $this->environment->sleep(Duration::hours(1));

        return $this->environment->await($activities->sendReceipt($charge));
    }
}
```

**Temporal PHP SDK**, with a static facade, generators and promises:

```php
#[WorkflowInterface]
interface OrderWorkflowContract
{
    #[AsWorkflowMethod]
    public function run(string $orderId);
}

final class OrderWorkflow implements OrderWorkflowContract
{
    public function run(string $orderId)
    {
        $activities = Workflow::newActivityStub(OrderActivities::class);

        $charge = yield $activities->charge($orderId);
        yield Workflow::timer(3600);

        return yield $activities->sendReceipt($charge);
    }
}
```

The steps, their names and their order are the same. The code around them differs:

| | Durable | Temporal PHP SDK |
|---|---|---|
| Access to the engine | `WorkflowEnvironment` injected in the constructor | static `Workflow::` facade |
| Suspension | fibers + `Awaitable` | `yield` + `React\Promise\PromiseInterface` |
| Function colouring | ordinary methods, declared return types | any awaiting method becomes a generator, and so does its caller; see [below](#5-fibers-or-generators-the-colouring-problem) |
| Declaration | `#[AsWorkflow]` on the class | `#[WorkflowInterface]` on an interface, implemented by a class |
| Method attributes | `#[AsWorkflowMethod]`, `#[AsSignalMethod]`, `#[AsQueryMethod]`, `#[AsUpdateMethod]` | the same four, workflow updates included |
| Compensations | `new Saga()`; each compensation calls `await()` itself, `compensate()` runs them in reverse order | `new Workflow\Saga()`; `yield $saga->compensate()` |

The return type shows the difference. In Durable, `run()` declares `string`. In the SDK, the only
type it could declare is `\Generator`, which says nothing about what the workflow returns. The
declared return type is what lets a PHPUnit test build the Durable class and call it like any
other object; see [Testability](#2-testability).

I kept the attribute vocabulary close to the SDK's on purpose. The execution model underneath
differs.

---

## 5. Fibers or generators: the colouring problem

The *function colouring* row above is the mechanism behind [Testability](#2-testability), the
second of the three properties listed there. This section looks at it in detail. The name comes
from Bob Nystrom's
[What Color Is Your Function?](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/).
If you have written JavaScript, you have met the idea: a function that uses `await` must be
declared `async`. In a language where suspension is a keyword, functions come in two colours.
A red function suspends and a blue one does not, and only another red function can call a red one.

In PHP generators, `yield` is that keyword. A method that yields is a **generator**: it no longer
returns its value, it returns a `Generator` that some other code has to drive. Suppose you extract
three lines of a workflow into a helper, an ordinary refactoring. If those lines await, the helper
turns red, and every caller up to the workflow method turns red with it.

**Durable**, where the helper is an ordinary method:

```php
#[AsWorkflowMethod]
public function run(string $orderId): string
{
    return $this->chargeWithRetry($orderId);
}

private function chargeWithRetry(string $orderId): string
{
    foreach ([1, 2, 4] as $backoff) {
        try {
            return $this->environment->await($this->activities->charge($orderId));
        } catch (DurableActivityFailedException) {
            $this->environment->sleep(Duration::seconds($backoff));
        }
    }

    throw new ChargeGaveUp($orderId);
}
```

**Temporal PHP SDK**, where the helper is a generator, and so is its caller:

```php
public function run(string $orderId)
{
    return yield from $this->chargeWithRetry($orderId);
}

private function chargeWithRetry(string $orderId)
{
    foreach ([1, 2, 4] as $backoff) {
        try {
            return yield $this->activities->charge($orderId);
        } catch (ActivityFailure) {
            yield Workflow::timer($backoff);
        }
    }

    throw new ChargeGaveUp($orderId);
}
```

In practice, a retry policy does this job. `ActivityOptions` carries one on both sides, and
[Failures and retries](../failures/) covers it. This example is about the **extraction**: three
lines moved out of a workflow method into a helper. On the SDK side, two return types disappear
and the call site changes to `yield from`. Both changes are the cost of the colour.

Durable suspends with `\Fiber::suspend()`, **inside the runtime**, in `ExecutionRuntime::await()`,
several frames below your code. A fiber suspends the whole call stack, not only the frame that
asked. The frames in between are suspended without taking part, so they need no keyword, no
return type change and no rewrite.

| | Durable (fibers) | Temporal PHP SDK (generators) |
|---|---|---|
| Awaiting from a helper method | ordinary private method | the helper becomes a generator |
| Its callers | unchanged | every one of them becomes a generator too, up to `#[AsWorkflowMethod]` |
| The call site | `$this->chargeWithRetry($id)` | `yield from $this->chargeWithRetry($id)` |
| Declared return type | the method's own, `string` | none it can usefully declare |
| Calling it from outside a workflow | an ordinary call | needs something to drive the generator |

[Testability](#2-testability) rests on that last row: PHPUnit can build and call a blue workflow
like any object.

### What the colour shows, and what fibers hide {#what-the-colour-buys-and-what-it-costs-to-give-up}

Colouring also has a benefit. `yield` **marks the suspension point in the source**: when you read
the method, you see exactly where the workflow can stop for a week. Fibers remove that marker. A
call that looks ordinary may suspend, and nothing at the call site shows it.

Durable limits that loss. **Only `await()` waits**, along with `sleep()`, which is a short way to
write `await()` on a timer. Every stub call, `timer()`, `all()`, `any()` and `some()` builds its
result and returns immediately. Inside a given method, the waiting points are exactly those calls.
What you cannot see from the call site is whether a helper waits *inside*. That is the price of the
refactoring that the SDK's model rules out.

Two limits to know:

- fibers need PHP **8.1+**; Durable requires 8.2 regardless;
- a fiber **cannot suspend in a destructor**: PHP throws `FiberError: Cannot switch fibers in
  current execution context`. Awaiting from `__destruct()` is not workflow code, so the case has
  not come up in practice, but it is the one context where the stack cannot suspend.

Neither model affects determinism. Both replay the same history, and both forbid the same
non-deterministic calls inside a workflow. With Durable, the suspension keyword lives in the
runtime; with the SDK, it lives in your code.

### Fiber support in progress in the SDK {#the-sdk-intends-to-close-this}

The SDK has an open pull request that adds a Fibers API
([#798](https://github.com/temporalio/sdk-php/pull/798)). It follows the issue that proposed
replacing yields with fiber suspension ([#702](https://github.com/temporalio/sdk-php/issues/702)).
Its maintainers have said that the change is prototyped and planned for an upcoming major. None of
it is in a release as of v2.18, and this section describes v2.18.

That change would settle the colouring difference, and only that one. What makes a workflow test
need a server is [the worker runtime](#1-the-worker-runtime-no-roadrunner), whichever way the
workflow suspends. A workflow still runs inside RoadRunner, driven by a task queue on a real cluster,
whether it suspends on a `yield` or on a fiber. After that change, [testability](#2-testability)
remains the larger difference: running a workflow to completion in the
test process and asserting on the value it returns, with no server to start and no second runtime
to supervise.

---

## 6. Scheduling activities

The SDK accepts both a typed stub and a call by activity name with a free-form payload. Durable
drops the second form: **the typed stub is the only way a workflow schedules an activity**
([DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md)).
The optional `gplanchat/durable-phpstan` extension resolves stub calls against the contract
interface, so a wrong argument becomes a static analysis error instead of a serialization failure
at runtime.

You give up the free-form call, and static analysis catches one class of mistakes. See
[Creating activities](../activities/).

---

## 7. Workflow versioning

Both let one class carry two behaviours and the run's history determines which one it sees:

```php
// Temporal PHP SDK
$v = yield Workflow::getVersion('add-discount', Workflow::DEFAULT_VERSION, 1);

// Durable
$v = $this->environment->version('add-discount', minSupported: ChangePoint::DEFAULT_VERSION, maxSupported: 1);
```

The wire format is the same. I read it off a history the Go SDK produced, emitted it from the
bridge, and the server accepted it. A versioned
Durable execution and a versioned Go execution record the identical `Version` marker and the
identical `TemporalChangeVersion` search attribute. When you ask which executions are still on an
old branch, the same query returns both.

The two differences concern what surrounds the primitive:

| | |
|---|---|
| **Worker versioning** | Build ids, deployment names, pinning a run to a worker version: the operational mechanism that lives in the worker and the task queue, outside workflow code. The SDK has it; Durable does not. |
| **Knowing when a branch is dead** | A query on the Temporal backend, for both. Durable's journal backends have no search attributes, so they offer no equivalent answer. |

See [Changing a running workflow](../deploying/).

---

## 8. Nexus: calling and serving operations from PHP {#8-nexus-the-one-place-durable-is-ahead}

[Nexus](https://docs.temporal.io/nexus) routes a call from a workflow to an operation served in
another namespace or another cluster. **A Durable workflow can call a Nexus operation and can serve
one. A workflow written with the official PHP SDK can do neither.**

```php
$checkout = $env->nexusStub(CheckoutContract::class, endpoint: 'checkout-endpoint');

$order = $env->await($checkout->placeOrder($cartId));
```

You write the contract once and both sides read it, so you never retype an operation name as a
string. This matters because the server validates only the endpoint. It rejects a malformed
endpoint outright, but accepts an empty or whitespace-only service or operation without an error,
and the call then waits for a handler whose name never matches.

As of v2.18, "Nexus" appears in the PHP SDK only as generated gRPC plumbing (endpoint CRUD on the
operator client, a task-slot option on the worker, history dumping), with no API a workflow can
reach. Temporal's own documentation has a Nexus section for Go, Java, Python, TypeScript and .NET,
and none for PHP.

**Nexus support in the SDK is in progress.** An integration is open in a pull request
([#768](https://github.com/temporalio/sdk-php/pull/768)), following the issue that opened the
subject ([#580](https://github.com/temporalio/sdk-php/issues/580)), and its maintainers have said
that it is planned for an upcoming major. Read the lead described in this section as one measured
in releases: the gap will not stay open.

On the Durable side, integration tests against a real Temporal server exercise the caller path:
round trips, cancellation and failure, operation bounds, and the naming rules for the endpoint,
service, operation and headers. On the handler side, they cover both response shapes and the
cancellation path, with a Durable caller and a Durable handler in the same test.

**The call also interoperates with other SDKs.** The payload travels as the caller wrote it, with
no wrapper and no envelope, so a handler written with another SDK reads the fields it declares.
This was measured against a handler served by the **Go SDK**, which declares
`Greeting{Name string}`, receives `{"name":"ada"}` and answers `hello ada`. The reverse direction
was measured too: a Go caller that invokes an operation served by Durable gets its own declared type
back, and the two histories are identical, event for event.

### Serving a Nexus operation {#serving-too}

A handler declares the operation it serves, and answers either now or later:

```php
#[AsNexusServiceHandler(contract: BillingContract::class)]
final class Billing implements BillingServed
{
    // Now, if you already have the answer: you have about nine seconds.
    public function verify(Order $order): Verdict { /* … */ }
}

// Later, for anything real: a workflow claims the operation and produces the result.
#[AsWorkflow('Charge')]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class Charge { /* … */ }
```

The nine seconds are the task's own `request-timeout`, which I measured; Durable adds no limit of
its own.
When a handler is still working as the timeout expires, its task is redelivered and starts over.
That budget is the reason for the deferred form, and the reason I built it before the immediate
one.

Cancellation needs no hook. Durable cancels the workflow that fulfils the operation, and a workflow
already observes its own cancellation with its compensations.

[Nexus operations](../nexus/) covers the whole surface.

**What this means for PHP.** No other PHP implementation serves Nexus, because no other PHP
implementation reaches Nexus at all. Until now, a PHP service could not be a Nexus provider. A team
running PHP was reachable over HTTP like any other service, but not through the boundary Temporal
gives to Go, Java, Python, TypeScript and .NET, with its durable operations, server-side
correlation and cancellation that follows the call. Durable puts PHP on both sides of that
boundary.

One limit is deliberate:

- **Temporal backend only.** Nexus routes to an endpoint served elsewhere. A backend that keeps its
  journal itself, in memory or in one database, has no such route and no fallback that keeps the call's meaning. The in-memory, DBAL
  and Illuminate backends therefore
  **fail immediately** with `NexusUnsupportedByBackendException`, whose message says to use the
  Temporal backend, so the workflow does not wait for a result nobody will produce. On the
  handler side, on Symfony, the check fails **when the container is built** if
  `durable.temporal.dsn` is not set, not at request time, because
  a handler with no route never receives a request at all.

[DUR036](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR036-nexus-caller-only-and-the-backend-asymmetry.md)
and [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md)
record the reasoning.

---

## 9. Where the SDK is ahead

| | |
|---|---|
| **Maintenance** | Official Temporal project, kept in parity with the other language SDKs |
| **Maturity** | Long production track record. Durable is `0.1.0-beta`, a pre-release: breaking changes between releases remain possible |
| **API coverage** | Broad. Durable covers search attributes, cron schedules, updates, deadlines and child workflows, but search attributes are **start options** here, while the SDK also lets a running workflow upsert its own. For anything beyond that, check the [Configuration reference](../configuration/) before you commit |

These differences are real, and **maturity** weighs the most. `0.1.0-beta` is still a pre-release,
so breaking changes between versions remain possible. Each one ships with its migration procedure,
and it is still a breaking change.

---

## Choosing

**Use the Temporal PHP SDK** when you already operate a Temporal cluster, want the officially
maintained client with cross-language parity, need **worker** versioning (build ids, pinning a run
to a worker version), and RoadRunner is acceptable in your deployment.

**Coming from the SDK?** `gplanchat/durable-rector` does the mechanical part of the migration. It
converts the attributes and the failure classes, and keeps the workflow and activity **type
names** already recorded on a running server, the part a hand migration silently gets wrong. It
also converts the execution model: the static `Workflow::` facade becomes an injected environment,
and `yield` goes, along with the `\Generator` return type it leaves behind. It does not invent the
return type that replaces `\Generator`, and it does not convert what has no counterpart in Durable.
It adds a comment at those places instead, so you know before you start whether the migration is
open to you at all.

**Use Durable** when you want durable execution without adding a second runtime to your
application, when a single SQL database is the right operational footprint, when you want workflow
logic covered by unit tests that need no infrastructure, or when you need to **call or serve** Nexus
operations from PHP at all. In each case, you need to be able to accept a pre-release, with possible
breaking changes between releases.

---

## See also

- [Packages](../packages/) describes what each package contains and what it requires.
- [Backends](../backends/) compares In-Memory, DBAL, Illuminate and Temporal side by side.
- [Testing workflows](../testing/) covers the full testing toolkit.
- [Creating a workflow](../workflows/) covers the authoring surface in detail.
