---
title: Durable and the Temporal PHP SDK
weight: 18
---

# Durable and the Temporal PHP SDK

Temporal ships an [official PHP SDK](https://github.com/temporalio/sdk-php). Durable is not a fork
of it, not a wrapper around it, and does not depend on it: `composer.lock` contains no
`temporal/sdk` and no RoadRunner package. The two solve the same problem — durable execution of
long-running business logic — and they make different trade-offs at every layer below that.

This page states those differences, including the ones where the SDK is ahead.

---

## 1. The worker runtime: no RoadRunner

The SDK splits into a **client** and a **worker**. The client needs `ext-grpc`; the worker needs
**RoadRunner**, a Go application server downloaded into the project with `./vendor/bin/rr get` and
configured through its own `.rr.yaml`. Workflow and activity code runs inside PHP processes that
RoadRunner supervises.

Durable has no second runtime. Workflow and activity work is delivered by **Symfony Messenger**,
and a worker is an ordinary PHP CLI consumer:

```bash
bin/console messenger:consume durable_workflows durable_activities
```

| | Durable | Temporal PHP SDK |
|---|---|---|
| Worker process | `messenger:consume`, supervised by whatever already supervises your workers | RoadRunner (Go binary), supervised by RoadRunner |
| Extra binary in the image | no | yes |
| Worker configuration | `messenger.yaml` | `.rr.yaml` |
| Deployment model | the one your Symfony application already uses | a second process model to learn and operate |

### What this does *not* claim

Durable does not remove gRPC. When the backend is Temporal, the bridge speaks gRPC to the cluster
and **`ext-grpc` is required** — it is declared by `gplanchat/durable-bridge-temporal`, not by the
core package:

| Package | Requires |
|---|---|
| `gplanchat/durable` | `php >= 8.2`, `psr/cache` — nothing else |
| `gplanchat/durable-bridge-temporal` | `ext-grpc`, `grpc/grpc`, `google/protobuf`, `symfony/messenger` |
| `gplanchat/durable-bridge-dbal` | `doctrine/dbal`, `symfony/lock`, `symfony/messenger` |

So: **no RoadRunner, ever; `ext-grpc` only when you talk to a Temporal cluster.** On the In-Memory
and DBAL backends, no PHP extension beyond a standard install is involved. The rule behind this is
recorded in [DUR006](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR006-no-official-temporal-php-sdk-and-no-roadrunner.md).

---

## 2. Testability

This is where the two libraries diverge most, and the divergence is structural rather than a matter
of tooling: it follows from how a workflow reaches the engine.

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

No server, no binary, no extension, no Docker. See [Testing workflows](../testing/) for the full
toolkit.

### With the SDK, every workflow test is an integration test

The SDK's test environment boots a **Temporal test server** *and* a **RoadRunner worker** from a
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

The workflow never executes in the PHPUnit process. Activity mocks are an out-of-process channel:
the expectation is written on one side and read by the worker on the other. This is faithful — it
*is* a real Temporal server — but there is no cheaper tier below it. Asserting that a `match` in
your workflow picks the right branch costs two binaries and a gRPC round trip.

### Why Durable can do this

Three properties of the authoring surface, not three test helpers:

- **The environment is injected, not static.** `Workflow::newActivityStub()` reads a static context
  bound to the running worker and throws `OutOfContextException` outside it. A Durable workflow
  receives `WorkflowEnvironment` through its **constructor**, so it is an ordinary PHP object a test
  can build. There is no global state to reset between tests.
- **Fibers instead of generators.** A workflow method returns its declared type. PHPUnit compares a
  value; it does not drive a generator or resolve a promise.
- **The test runs the production class.** `runWorkflowClass()` goes through the same constructor,
  the same attributes, the same `#[WorkflowMethod]` — see
  [DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md).

### What you can assert

Durable exposes the **event journal** to the test, not just the return value:

| `DurableTestCase` | `ActivitySpy` |
|---|---|
| `assertWorkflowCompleted()` | `ActivitySpy::returns()` / `throws()` / `returnsSequence()` |
| `assertWorkflowFailed($failureClass)` | `assertCalledWith()` / `assertFirstCallWith()` |
| `assertActivityExecuted()` | `assertCalledTimes()` / `assertCalledOnce()` / `assertNotCalled()` |
| `assertEventStoreContains($eventClass)` | `calls()` / `callCount()` |
| `countActivityExecutions()` | |

`countActivityExecutions()` is the one to keep in mind: it proves an activity was **not** re-run
after a retry. A black-box assertion on the result cannot see that.

For Symfony integration tests, `DurableBundleTestTrait` does the same inside `KernelTestCase`,
draining the Messenger transports until the run settles.

### The tier that does need a server

Durable's own integration suite runs against a **real Temporal server** — `ext-grpc`, a running
`temporal server start-dev`, and PHP worker processes spawned by the test case:

```bash
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

The suite is skipped when `DURABLE_TEMPORAL_ADDRESS` is unset.

The difference from the SDK is not "no server" — it is **which tests need one**. This tier exists to
prove that the bridge's commands are accepted by a real server: round trips, failure paths,
deadlines, updates, cron schedules, search attributes, Nexus. It is deliberately narrow, and it is
about the *bridge*, not about your business logic. Your workflows are covered by the unit tier, which
needs nothing. With the SDK, the server-backed tier is the only tier there is.

| | Durable | Temporal PHP SDK |
|---|---|---|
| Unit tier (business logic) | PHPUnit, in-process, zero infrastructure | none — every workflow test is out-of-process |
| Server-backed tier | optional, scoped to wire and protocol parity | mandatory, for all workflow tests |
| What it needs | a Temporal dev server + `ext-grpc` | test server + RoadRunner |
| Runs in CI without Docker | the unit tier does | no |

### The honest cost

A passing In-Memory test does not prove Temporal behaves the same. That risk is real, and it is
managed rather than denied:

- [DUR018](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR018-temporal-event-parity-replay-and-slots.md)
  requires event and slot parity between In-Memory and Temporal;
- [DUR016](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR016-in-memory-backend-exception-rules.md)
  bounds what an In-Memory implementation may simplify, and requires each shortcut to justify itself
  in a docblock;
- the integration tier above is what actually checks it.

Time skipping is **not** among the things you give up. The In-Memory runner holds a virtual clock
and advances it to the next timer's due date, so `sleep(3600)` settles in a millisecond of real
time. It only skips when nothing else can progress — skipping while an activity could still
complete would make the timer win every `any(activity, timer)` race. See
[Testing workflows](../testing/#time-is-skipped-not-waited-for).

---

## 3. Backends: one, or three

| | Durable | Temporal PHP SDK |
|---|---|---|
| Execution backends | **three**, running the same workflow code | a Temporal cluster |
| Tests | In-Memory, no server | test server |
| Production without a cluster | **DBAL** — durable execution on one SQL database | not possible |

The DBAL backend ([DUR030](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md))
has no counterpart in the SDK: a journal, workflow metadata and locks on a single relational
database, no cluster and no `ext-grpc`. For an application that needs durable execution but not the
operational surface of a Temporal deployment, this is often the deciding difference — more than the
worker runtime.

Switching is a configuration change (`durable.event_store.type`); the workflow code does not move.
See [Backends](../backends/).

---

## 4. The authoring surface

The same workflow — charge an order, wait an hour, send the receipt — written twice.

**Durable** — injected environment, fibers, plain return types:

```php
#[Workflow(name: 'order')]
final class OrderWorkflow implements OrderWorkflowContract
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(string $orderId): string
    {
        $activities = $this->environment->activityStub(OrderActivities::class);

        $charge = $this->environment->await($activities->charge($orderId));
        $this->environment->sleep(Duration::hours(1));

        return $this->environment->await($activities->sendReceipt($charge));
    }
}
```

**Temporal PHP SDK** — static facade, generators, promises:

```php
#[WorkflowInterface]
interface OrderWorkflowContract
{
    #[WorkflowMethod]
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

Same steps, same names, same order. What differs is everything around them:

| | Durable | Temporal PHP SDK |
|---|---|---|
| Access to the engine | `WorkflowEnvironment` injected in the constructor | static `Workflow::` facade |
| Suspension | fibers + `Awaitable` | `yield` + `React\Promise\PromiseInterface` |
| Function colouring | ordinary methods, declared return types | any awaiting method becomes a generator, and so does its caller — see [below](#5-fibers-or-generators-the-colouring-problem) |
| Declaration | `#[Workflow]` on the class | `#[WorkflowInterface]` on an interface, implemented by a class |
| Method attributes | `#[WorkflowMethod]`, `#[SignalMethod]`, `#[QueryMethod]`, `#[UpdateMethod]` | the same four, workflow updates included |

The return type is the visible consequence: `run()` declares `string` on one side; on the other, the
only type it could declare is `\Generator`, which says nothing about what the workflow returns. That
is what makes the Durable class an ordinary object a PHPUnit test can build and call — see
[Testability](#2-testability).

The attribute vocabulary is deliberately close; the execution model underneath is not.

---

## 5. Fibers or generators: the colouring problem

The *function colouring* row above is the mechanism under
[Testability](#2-testability) — it is the second of the three properties listed there, and it is
worth its own section. The name comes from Bob Nystrom's
[What Color Is Your Function?](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/):
in a language where suspension is a keyword, functions come in two colours — red suspends, blue
does not — and a red one can only be called from another red one.

`yield` is that keyword. A method that yields is a **generator**: it no longer returns its value, it
returns a `Generator` that somebody has to drive. Extract three lines of a workflow into a helper —
the ordinary refactoring — and if those lines await, the helper turns red, and every caller up to
the workflow method turns red with it.

**Durable** — the helper is an ordinary method:

```php
#[WorkflowMethod]
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

**Temporal PHP SDK** — the helper is a generator, and so is its caller:

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

A retry policy would normally do this for you — `ActivityOptions` carries one on both sides, and
[Failures and retries](../failures/) is where it belongs. What the example is about is the
**extraction**: three lines moved out of a workflow method into a helper. Two return types
disappear, and the call site changes to `yield from`. Neither is a detail — they are what the
colour costs.

Durable suspends with `\Fiber::suspend()`, and it does so **inside the runtime**, in
`ExecutionRuntime::await()`, several frames below your code. A fiber suspends the whole call stack,
not the frame that asked: the frames in between are suspended without participating, so they need
no keyword, no return type change, and no rewrite.

| | Durable (fibers) | Temporal PHP SDK (generators) |
|---|---|---|
| Awaiting from a helper method | ordinary private method | the helper becomes a generator |
| Its callers | unchanged | every one of them becomes a generator too, up to `#[WorkflowMethod]` |
| The call site | `$this->chargeWithRetry($id)` | `yield from $this->chargeWithRetry($id)` |
| Declared return type | the method's own — `string` | none it can usefully declare |
| Calling it from outside a workflow | an ordinary call | needs something to drive the generator |

That last row is what [Testability](#2-testability) rests on: a blue workflow is an object PHPUnit
builds and calls.

### What the colour buys, and what it costs to give up

Colouring is not only a tax. `yield` **marks the suspension point in the source** — reading the
method, you know exactly where the workflow can stop for a week. Fibers take that marker away: an
ordinary-looking call may suspend and nothing at the call site says so.

Durable narrows the loss rather than denying it. **Only `await()` waits**, and `sleep()`, which is
`await()` on a timer written short — every stub call, `timer()`, `all()`, `any()` and `some()`
assembles and returns immediately. Inside a given method, the waiting points are exactly those
calls. What a reader cannot see is whether a helper waits *inside*, which is the price of the
refactoring the SDK forbids.

Two limits worth knowing:

- fibers are PHP **8.1+**; Durable requires 8.2 regardless;
- a fiber **cannot suspend in a destructor** — PHP throws `FiberError: Cannot switch fibers in
  current execution context`. Awaiting from `__destruct()` is not workflow code, so this has not
  come up in practice, but it is the one context where the stack is not free to suspend.

Neither model affects determinism: both replay the same history, and both forbid the same
non-deterministic calls inside a workflow. The difference is where the suspension keyword lives —
in your code, or in the runtime.

---

## 6. Scheduling activities

The SDK accepts both a typed stub and a call by activity name with a free-form payload. Durable
removed the second form: **the typed stub is the only way a workflow schedules an activity**
([DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md)),
and the optional `gplanchat/durable-phpstan` extension resolves stub calls against the contract
interface so a wrong argument is a static analysis error rather than a serialization failure at
runtime.

Less freedom, one class of mistakes removed at analysis time. See
[Creating activities](../activities/).

---

## 7. Nexus: the one place Durable is ahead

[Nexus](https://docs.temporal.io/nexus) routes a call from a workflow to an operation served in
another namespace or another cluster. **A Durable workflow can call one; a workflow written with the
official PHP SDK cannot.**

```php
$order = $this->environment->await(
    $this->environment->nexusOperation(
        'checkout-endpoint',
        'com.example.checkout',
        'placeOrder',
        ['cartId' => $cartId],
    ),
);
```

The three names are value objects rather than strings, because the server only guards the first: it
refuses a malformed endpoint outright, and accepts an empty or whitespace-only service or operation
without a word — leaving the call waiting for a handler whose name will never match.

At the time of writing, "Nexus" appears in the PHP SDK only as generated gRPC plumbing — endpoint
CRUD on the operator client, a task-slot option on the worker, history dumping — with no API a
workflow can reach. Temporal's own documentation carries a Nexus section for Go, Java, Python,
TypeScript and .NET, and none for PHP. On the Durable side the caller path is exercised by
integration tests against a real Temporal server: round trips, cancellation and failure, operation
bounds, and the endpoint, service, operation and header naming rules.

Two limits come with it, and both are deliberate:

- **Caller only.** Durable calls Nexus operations; it does not serve them. A handler needs its own
  Nexus task worker, poll loop, dispatch and failure vocabulary — none of which the caller path
  touches. That is a separate piece of work, not an oversight.
- **Temporal backend only.** Nexus routes to an endpoint served elsewhere; a backend keeping its
  journal in one database has no such route and no honest fallback. The DBAL backend therefore
  **refuses immediately** with `NexusUnsupportedByBackendException`, which names the backend and
  what to do instead, rather than leaving the workflow waiting on a result nobody will produce.

The reasoning is recorded in
[DUR036](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR036-nexus-caller-only-and-the-backend-asymmetry.md).

---

## 8. Where the SDK is ahead

| | |
|---|---|
| **Maintenance** | Official Temporal project, kept in parity with the other language SDKs |
| **Maturity** | Long production track record. Durable is `0.1.0-alpha`, with breaking changes between alphas |
| **Workflow versioning** | `Workflow::getVersion()`. **Durable has no equivalent** — this is the significant functional gap for long-running workflows that must evolve while runs are in flight |
| **Saga** | A dedicated helper. Durable has none — the shape is a deadline and a compensation path, written out in [Creating a workflow](../workflows/#bounding-a-wait-in-time), so what is missing is the sugar rather than the capability |
| **API coverage** | Broad. Durable covers search attributes, cron schedules, updates, deadlines and child workflows — but search attributes are **start options** here, where the SDK also lets a running workflow upsert its own; anything beyond that is worth checking against the [Configuration reference](../configuration/) before you commit |

A comparison with no losses column is marketing. These are real, and the versioning gap in
particular should be weighed before choosing Durable for workflows expected to run for months.

---

## Choosing

**Use the Temporal PHP SDK** when you already operate a Temporal cluster, want the officially
maintained client with cross-language parity, need workflow versioning or a Nexus **handler**, and
RoadRunner is acceptable in your deployment.

**Coming from the SDK?** `gplanchat/durable-rector` rewrites the attribute half of the migration —
`#[WorkflowInterface]`, `#[ActivityInterface]` and the failure classes — and it does so keeping the
workflow and activity **type names** a running server already knows, which is the part a hand
migration silently gets wrong. The execution model (`yield`, the static `Workflow::` facade) it does
not touch, and §5 above is why.

**Use Durable** when you want durable execution without adding a second runtime to your Symfony
application, when a single SQL database is the right operational footprint, when you want workflow
logic covered by unit tests that need no infrastructure, or when you need to **call** Nexus
operations from PHP at all — and when an alpha with breaking changes between releases is a trade you
can make.

---

## See also

- [Packages](../packages/) — what each package contains and what it requires.
- [Backends](../backends/) — In-Memory, DBAL and Temporal side by side.
- [Testing workflows](../testing/) — the full testing toolkit.
- [Creating a workflow](../workflows/) — the authoring surface in detail.
