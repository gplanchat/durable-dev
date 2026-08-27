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

| | Temporal PHP SDK | Durable |
|---|---|---|
| Worker process | RoadRunner (Go binary), supervised by RoadRunner | `messenger:consume`, supervised by whatever already supervises your workers |
| Extra binary in the image | yes | no |
| Worker configuration | `.rr.yaml` | `messenger.yaml` |
| Deployment model | a second process model to learn and operate | the one your Symfony application already uses |

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
*is* a real Temporal server, with time skipping — but there is no cheaper tier below it. Asserting
that a `match` in your workflow picks the right branch costs two binaries and a gRPC round trip.

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

### Why it is possible here

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

| | Temporal PHP SDK | Durable |
|---|---|---|
| Unit tier (business logic) | none — every workflow test is out-of-process | PHPUnit, in-process, zero infrastructure |
| Server-backed tier | mandatory, for all workflow tests | optional, scoped to wire and protocol parity |
| What it needs | test server + RoadRunner | a Temporal dev server + `ext-grpc` |
| Runs in CI without Docker | no | the unit tier does |

### The honest cost

A passing In-Memory test does not prove Temporal behaves the same. That risk is real, and it is
managed rather than denied:

- [DUR018](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR018-temporal-event-parity-replay-and-slots.md)
  requires event and slot parity between In-Memory and Temporal;
- [DUR016](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR016-in-memory-backend-exception-rules.md)
  bounds what an In-Memory implementation may simplify, and requires each shortcut to justify itself
  in a docblock;
- the integration tier above is what actually checks it.

Durable also has **no equivalent to Temporal's time skipping**. In-Memory timers are driven by the
runner, which covers the common case, but not scenarios that depend on real wall-clock dates.

---

## 3. Backends: one, or three

| | Temporal PHP SDK | Durable |
|---|---|---|
| Execution backends | a Temporal cluster | **three**, running the same workflow code |
| Tests | test server | In-Memory, no server |
| Production without a cluster | not possible | **DBAL** — durable execution on one SQL database |

The DBAL backend ([DUR030](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR030-dbal-backend-simplified-durable-execution.md))
has no counterpart in the SDK: a journal, workflow metadata and locks on a single relational
database, no cluster and no `ext-grpc`. For an application that needs durable execution but not the
operational surface of a Temporal deployment, this is often the deciding difference — more than the
worker runtime.

Switching is a configuration change (`durable.event_store.type`); the workflow code does not move.
See [Backends](../backends/).

---

## 4. The authoring surface

**Temporal PHP SDK** — static facade, generators, promises:

```php
#[WorkflowMethod]
public function handle(string $input)
{
    $activity = Workflow::newActivityStub(MyActivity::class);
    $result = yield $activity->doWork($input);
    yield Workflow::sleep(60);

    return $result;
}
```

**Durable** — injected environment, fibers, plain return types:

```php
#[Workflow('TimerThenTickWorkflow')]
final class TimerThenTickWorkflow
{
    private readonly ActivityStub $tick;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->tick = $environment->activityStub(TickActivityInterface::class);
    }

    #[WorkflowMethod]
    public function run(float $seconds = 0.01): string
    {
        $this->environment->timer($seconds);

        return $this->environment->await($this->tick->tick());
    }
}
```

| | Temporal PHP SDK | Durable |
|---|---|---|
| Access to the engine | static `Workflow::` facade | `WorkflowEnvironment` injected in the constructor |
| Suspension | `yield` + `React\Promise\PromiseInterface` | fibers + `Awaitable` |
| Function colouring | any awaiting method becomes a generator, and so does its caller | ordinary methods, declared return types |
| Declaration | `#[WorkflowInterface]` on an interface, implemented by a class | `#[Workflow]` on the class |
| Method attributes | `#[WorkflowMethod]`, `#[SignalMethod]`, `#[QueryMethod]`, plus workflow updates | the same four |

The attribute vocabulary is deliberately close; the execution model underneath is not.

---

## 5. Scheduling activities

The SDK accepts both a typed stub and a call by activity name with a free-form payload. Durable
removed the second form: **the typed stub is the only way a workflow schedules an activity**
([DUR039](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR039-workflow-authoring-surface.md)),
and the optional `gplanchat/durable-phpstan` extension resolves stub calls against the contract
interface so a wrong argument is a static analysis error rather than a serialization failure at
runtime.

Less freedom, one class of mistakes removed at analysis time. See
[Creating activities](../activities/).

---

## 6. Where the SDK is ahead

| | |
|---|---|
| **Maintenance** | Official Temporal project, kept in parity with the other language SDKs |
| **Maturity** | Long production track record. Durable is `0.1.0-alpha`, with breaking changes between alphas |
| **Workflow versioning** | `Workflow::getVersion()`. **Durable has no equivalent** — this is the significant functional gap for long-running workflows that must evolve while runs are in flight |
| **Time skipping in tests** | Provided by the test server. No Durable equivalent |
| **Nexus** | Full support. Durable is **caller-only** ([DUR036](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR036-nexus-caller-only-and-the-backend-asymmetry.md)) |
| **Saga** | Dedicated helper. In Durable, written by hand |
| **API coverage** | Broad. Durable covers search attributes, cron schedules, updates, deadlines and child workflows; anything beyond that is worth checking against the [Configuration reference](../configuration/) before you commit |

A comparison with no losses column is marketing. These are real, and the versioning gap in
particular should be weighed before choosing Durable for workflows expected to run for months.

---

## Choosing

**Use the Temporal PHP SDK** when you already operate a Temporal cluster, want the officially
maintained client with cross-language parity, need workflow versioning or full Nexus support, and
RoadRunner is acceptable in your deployment.

**Use Durable** when you want durable execution without adding a second runtime to your Symfony
application, when a single SQL database is the right operational footprint, when you want workflow
logic covered by unit tests that need no infrastructure — and when an alpha with breaking changes
between releases is a trade you can make.

---

## See also

- [Packages](../packages/) — what each package contains and what it requires.
- [Backends](../backends/) — In-Memory, DBAL and Temporal side by side.
- [Testing workflows](../testing/) — the full testing toolkit.
- [Creating a workflow](../workflows/) — the authoring surface in detail.
