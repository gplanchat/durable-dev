---
title: Testing workflows
weight: 40
---

# Testing workflows

Durable ships a **testing toolkit** for your workflows and activities, built on standard PHPUnit.
A workflow describes the steps of an execution, and an activity is one of those steps that has a
side effect, such as an HTTP call; see the [glossary](../glossary/). Pick the entry point that
matches your tests, framework-agnostic, Symfony bundle or Laravel integration:

| Utility | Package | When to use |
|---|---|---|
| `DurableTestCase` + `ActivitySpy` + `WorkflowTestEnvironment` | `gplanchat/durable` | Pure unit / functional tests, no Symfony container. |
| `DurableBundleTestTrait` | `gplanchat/durable-bundle` | Symfony `KernelTestCase`-based integration tests. |
| `DurableLaravelTestTrait` | `gplanchat/durable-laravel` | Laravel integration tests, on the application's configured backend. |

---

## Unit and functional tests with `DurableTestCase` {#unit-and-functional-tests--durabletestcase}

`DurableTestCase` is an abstract PHPUnit `TestCase` that wires an **in-memory backend** for you:
the journal, the record of an execution's steps and their results, stays in memory. Extend it, call
`createWorkflowTestEnvironment()`, run your workflow, then check the result with the built-in
assertions.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Workflow\GreetWorkflow;
use Gplanchat\Durable\Testing\ActivitySpy;
use Gplanchat\Durable\Testing\DurableTestCase;
use Gplanchat\Durable\WorkflowEnvironment;

final class GreetWorkflowTest extends DurableTestCase
{
    public function testWorkflowGreetsCorrectly(): void
    {
        // 1. Build a spy that returns a fixed value when the activity is called.
        $greetSpy = ActivitySpy::returns('Hello, Alice!');

        // 2. Create an in-memory environment and register the spy under the activity name.
        $env = $this->createWorkflowTestEnvironment(['greet' => $greetSpy]);

        // 3. Run your workflow class, in the shape it has in production: the environment
        //    reaches its constructor, the input reaches its #[AsWorkflowMethod].
        $result = $env->runWorkflowClass(
            GreetingWorkflow::class,
            ['name' => 'Alice'],
            $executionId = 'exec-greet-001',
        );

        // 4. Assert the result and verify the activity was called. The stub rebuilds the
        //    payload from the contract's parameter names, which is what the spy observes.
        self::assertSame('Hello, Alice!', $result);
        $greetSpy->assertCalledTimes(1);
        $greetSpy->assertCalledWith(['name' => 'Alice']);

        // 5. Assert event-store invariants (optional, for deeper coverage).
        $this->assertWorkflowCompleted($executionId, 'Hello, Alice!');
        $this->assertActivityExecuted($executionId, 'greet');
    }
}
```

The workflow and the contract under test, the same two files you would write for production:

```php
interface GreetingActivities
{
    #[AsActivityMethod('greet')]
    public function greet(string $name): string;
}

#[AsWorkflow(name: 'greeting')]
final class GreetingWorkflow
{
    /** @param ActivityStub<GreetingActivities> $greetings */
    #[AsWorkflowMethod]
    public function run(
        string $name,
        #[Activities(GreetingActivities::class)]
        ActivityStub $greetings,
        WorkflowEnvironment $env,
    ): string {
        return $env->await($greetings->greet($name));
    }
}
```

> [!NOTE]
> `run()` also accepts a closure receiving the environment, and a few tests below use it for a
> three-line workflow that is not worth a class. That form is the **harness's** shape, not a
> workflow's: a workflow class receives the environment and its stubs as parameters of its
> workflow method, or the environment through its constructor. Prefer `runWorkflowClass()`, so
> that what you test is what you ship.

### Available assertions in `DurableTestCase`

| Method | Description |
|---|---|
| `assertWorkflowCompleted($executionId, $expected)` | The workflow reached `ExecutionCompleted` with the given result. |
| `assertWorkflowFailed($executionId, $class = '')` | The workflow reached `WorkflowExecutionFailed`, optionally with a specific exception class. |
| `assertActivityExecuted($executionId, $name)` | An `ActivityScheduled` event with that name exists in the journal. |
| `assertEventStoreContains($executionId, $class)` | Any event of the given class is present for this execution. |
| `countActivityExecutions($executionId, $name)` | Returns how many times a named activity was scheduled. |

---

## Controlling activity behaviour with `ActivitySpy` {#controlling-activity-behaviour--activityspy}

`ActivitySpy` is a **callable test double** for activities. Set its return value, make it throw, or give it a sequence of results to simulate retries.

### Always return the same value

```php
$spy = ActivitySpy::returns('fixed-result');
```

### Always throw an exception

```php
$spy = ActivitySpy::throws(new \RuntimeException('External API unavailable'));
```

### Return a sequence (useful for retry scenarios)

The first call returns the first value, the second call the second, and so on.
A `\Throwable` in the sequence is **thrown** on its attempt.
Once the sequence is exhausted, the spy repeats the last entry.

```php
$spy = ActivitySpy::returnsSequence(
    new \RuntimeException('Temporary failure'), // attempt 1 → throws
    new \RuntimeException('Still failing'),     // attempt 2 → throws
    'Success after retries',                    // attempt 3 → returns
);
```

### Inspecting calls

```php
$spy->calls();          // list of all payloads received, e.g. [['name' => 'Alice']]
$spy->callCount();      // how many times the spy was invoked

$spy->assertCalledTimes(1);
$spy->assertCalledWith(['name' => 'Alice']);          // first call
$spy->assertCalledWith(['name' => 'Bob'], index: 1); // second call (0-based index)
$spy->assertNeverCalled();
```

---

## Low-level environment: `WorkflowTestEnvironment` {#low-level-environment--workflowtestenvironment}

`DurableTestCase` relies on `WorkflowTestEnvironment`. Use it directly when you do not want to extend `DurableTestCase`, for instance in test-support helper classes.

```php
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;

interface ShoutActivities
{
    #[AsActivityMethod('my-activity')]
    public function shout(string $text): string;
}

$env = WorkflowTestEnvironment::inMemory(['my-activity' => fn(array $p) => strtoupper($p['text'])]);

$result = $env->run(function (WorkflowEnvironment $wf) {
    return $wf->await($wf->activityStub(ShoutActivities::class)->shout('hello'));
}, 'exec-001');

assert($result === 'HELLO');
```

`WorkflowTestEnvironment` exposes:

- `run(callable $workflow, string $executionId): mixed` runs the workflow closure.
- `getEventStore(): EventStoreInterface` reads the in-memory event store.
- `getRunner(): InMemoryWorkflowRunner` reaches the underlying runner directly.
- `getActivityTransport()` inspects the in-memory activity queue.

---

## Symfony integration tests with `DurableBundleTestTrait` {#symfony-integration-tests--durablebundletesttrait}

For tests that boot your Symfony application kernel, use `DurableBundleTestTrait` in any class that extends `KernelTestCase`. The trait relies on **Messenger transports** configured as **in-memory** in the `test` environment (see [Getting started](../getting-started/)).

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Workflow\OrderWorkflow;
use Gplanchat\Durable\Bundle\Testing\DurableBundleTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderWorkflowIntegrationTest extends KernelTestCase
{
    use DurableBundleTestTrait;

    public function testOrderWorkflowCompletesSuccessfully(): void
    {
        self::bootKernel();

        // Dispatch the workflow into the in-memory Messenger transport.
        $executionId = $this->dispatchWorkflow(OrderWorkflow::class, [
            'orderId' => 'ORD-123',
            'amount'  => 99.90,
        ]);

        // Drain transports until the workflow reaches a terminal state.
        $this->drainMessengerUntilSettled($executionId);

        // Assert the final result.
        $this->assertWorkflowResultEquals($executionId, ['status' => 'charged', 'orderId' => 'ORD-123']);
    }

    public function testOrderWorkflowFailsWhenAmountIsNegative(): void
    {
        self::bootKernel();

        $executionId = $this->dispatchWorkflow(OrderWorkflow::class, [
            'orderId' => 'ORD-999',
            'amount'  => -1.0,
        ]);

        $this->drainMessengerUntilSettled($executionId);

        $this->assertWorkflowFailed($executionId, \InvalidArgumentException::class);
    }
}
```

### Prerequisites

In `config/packages/messenger.yaml`, under `when@test:`, declare in-memory transports whose names match `DurableBundleTestTrait::$durableWorkflowTransports`:

```yaml
when@test:
    framework:
        messenger:
            transports:
                durable_workflows:  'in-memory://'
                durable_activities: 'in-memory://'
```

### Customising the transport list or drain timeout

To change the transport list or the drain timeout, override the static properties before each test:

```php
protected function setUp(): void
{
    parent::setUp();
    // Add a custom transport name if your application declares one.
    static::$durableWorkflowTransports = ['durable_workflows', 'durable_activities', 'my_custom_transport'];
    // Extend the maximum drain time (seconds) for slow CI machines.
    static::$durableMaxDrainSeconds = 60.0;
}
```

### Methods provided by `DurableBundleTestTrait`

| Method | Description |
|---|---|
| `dispatchWorkflow($class, $input, $executionId?)` | Dispatches a workflow and returns its `executionId`. |
| `drainMessengerUntilSettled($executionId)` | Processes messages in all configured transports until the workflow terminates. Throws if the timeout is reached. |
| `assertWorkflowResultEquals($executionId, $expected)` | Asserts the workflow completed with the given result. |
| `assertWorkflowFailed($executionId, $class?)` | Asserts the workflow failed, optionally matching the exception class. |
| `getEventStoreService()` | Returns the `EventStoreInterface` from the test container for low-level inspection. |
| `getDataCollector()` | Returns the `DurableDataCollector` when the profiler is enabled (debug kernel). |

---

## Laravel integration tests with `DurableLaravelTestTrait` {#laravel-integration-tests--durablelaraveltesttrait}

Use `DurableLaravelTestTrait` in a test class that extends Laravel's
`Illuminate\Foundation\Testing\TestCase` (or Testbench's), which provides `$this->app`. The trait
offers the same four operations as the Symfony one, against the backend the application configures.
Declare the workflow in the `workflows` key of `config/durable.php`, as in production.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Workflow\OrderWorkflow;
use Gplanchat\Durable\Laravel\Testing\DurableLaravelTestTrait;
use Tests\TestCase;

final class OrderWorkflowTest extends TestCase
{
    use DurableLaravelTestTrait;

    public function testOrderWorkflowCompletesSuccessfully(): void
    {
        $executionId = $this->dispatchWorkflow(OrderWorkflow::class, [
            'orderId' => 'ORD-123',
            'amount'  => 99.90,
        ]);

        $this->drainUntilSettled($executionId);

        $this->assertWorkflowResultEquals($executionId, ['status' => 'charged', 'orderId' => 'ORD-123']);
    }
}
```

`drainUntilSettled()` depends on the backend. On `memory`, it drives the runs the process has
queued, as `durable:drain` does, and throws a `\RuntimeException` if the run is still open when the
drain ends, for instance when it waits on a signal. On `illuminate`, it runs
`queue:work --stop-when-empty` until the run completes or fails, within 30 seconds. A workflow that
fails ends the drain without throwing: assert on it with `assertWorkflowFailed()`.

### The Symfony and Laravel helpers side by side {#the-three-hosts-side-by-side}

| Operation | Symfony (`DurableBundleTestTrait`) | Laravel (`DurableLaravelTestTrait`) |
|---|---|---|
| Start | `dispatchWorkflow($class, $input, $executionId?)` | `dispatchWorkflow($class, $input, $executionId?)` |
| Drain | `drainMessengerUntilSettled($executionId)` | `drainUntilSettled($executionId)` |
| Read the result | `assertWorkflowResultEquals($executionId, $expected)` | `assertWorkflowResultEquals($executionId, $expected)` |
| Assert on the journal | `assertWorkflowFailed($executionId, $class?)` | `assertWorkflowFailed($executionId, $class?)` |
| Event store | `getEventStoreService()` | `getEventStoreService()` |

The Symfony trait also offers `getDataCollector()`, for the profiler. Magento has no helper yet.

---

## Choosing the right testing layer

```
Unit / functional (no container)
  └── DurableTestCase + ActivitySpy
       → Fast, deterministic, isolated.  Ideal for workflow logic.

Symfony integration (container)
  └── KernelTestCase + DurableBundleTestTrait
       → Tests DI wiring, Messenger routing, activity handler injection.
          Slightly slower; use for end-to-end "happy path" scenarios.

Temporal integration (real Temporal server)
  └── tests/integration, run against a dev server
       → Verifies that commands are *accepted*, not merely well-formed.
```

---

## Testing against a real Temporal server

Unit tests check that the bridge builds well-formed protobuf commands. Only a real server shows
whether it **accepts** them.

```bash
temporal server start-dev --namespace durable-test --port 7233

DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

Without `DURABLE_TEMPORAL_ADDRESS`, the suite is skipped, so it stays harmless in a pipeline that has
no server.

The suite runs two workers in **separate processes**, as in production. Both roles long-poll for
tens of seconds, and alternating them in one process starves whichever role is not polling.

Some tests need namespace-level setup. The file that needs it documents that setup at its top:

```bash
temporal operator search-attribute create --name DurableOrderId --type Keyword
temporal operator search-attribute create --name DurableAmount  --type Int
temporal operator search-attribute create --name DurablePrice   --type Double
```

---

## Timers run on a virtual clock in tests {#time-is-skipped-not-waited-for}

A workflow that sleeps runs in milliseconds under test. The harness uses a **virtual clock** and
advances it to the next due timer, so `sleep(Duration::hours(24))` takes no real time:

```php
interface PingActivities
{
    #[AsActivityMethod('ping')]
    public function ping(): string;
}

$result = $env->run(function (WorkflowEnvironment $wf): string {
    $wf->sleep(Duration::hours(1));
    $answer = $wf->await($wf->activityStub(PingActivities::class)->ping());
    $wf->sleep(Duration::hours(24));

    return $answer;
}, 'nightly-1');
```

The clock only moves when **nothing else can progress**. Advancing it earlier would make the timer
win every `any(activity, timer)` race that the activity was about to win. Because the clock waits,
a race has the same outcome here as in production.

---

## Stuck executions, endless retries and endless chains in the in-memory runner {#two-traps-of-the-in-memory-runner}

**A stuck execution fails.** A workflow waiting on a signal that the test never delivers raises `WorkflowStuckException`.

**Attempts are unlimited by default.** An activity that always fails retries forever, so the runner
enforces an overall budget. When the budget runs out, the runner reports which of the two situations
applies:

```
Workflow x did not finish within 10.0s. Activities retry indefinitely by default
(RetryLimit::unlimited(), Temporal semantics): pass RetryLimit::ofAttempts(n) or
RetryLimit::once(), declare the exception non-retryable, or raise the runner budget.
```

```php
$env = WorkflowTestEnvironment::inMemory(
    ['charge' => $spy],
    budgetSeconds: 3.0,
);
```

Retry backoff takes real time, because a retry is queued on the transport instead of being recorded
as a timer, so an activity configured with the default one-second interval makes the test wait.
Pass `initialInterval: Duration::zero()` to keep tests fast.

**A continue-as-new chain stops after 10 continuations.** An execution that calls `continueAsNew()`
closes its journal and hands over to a fresh execution (continue-as-new; see the
[glossary](../glossary/)). The in-memory runner follows the chain and returns the result of the last
execution. Each execution in the chain gets its own budget, so the budget does not stop a workflow
that calls `continueAsNew()` every time. Past 10 continuations, the runner throws
`ContinuationCapReachedException`, a `WorkflowStuckException`, where `x` is the execution id you
started:

```
Workflow x continued as new more often than maxContinuations (10) allows. Give the workflow a run
that returns, or raise the runner's maxContinuations.
```

To test a longer chain, raise the cap:

```php
$env = WorkflowTestEnvironment::inMemory(maxContinuations: 50);
```

An inline child workflow keeps the default cap of 10, as it keeps the default budget, whatever cap
its parent's environment sets.

In a `DurableTestCase`, `createWorkflowTestEnvironment()` and `createWorkflowRunner()` take the
same two arguments, `budgetSeconds` and `maxContinuations`, and pass them to the runner:

```php
$env = $this->createWorkflowTestEnvironment(
    ['charge' => $spy],
    budgetSeconds: 3.0,
    maxContinuations: 50,
);
```

On Magento without a Temporal DSN, set the
`maxContinuations` argument of `RuntimeFactory` in `di.xml`, as for `budgetSeconds`.

---

## Testing child workflows

Register the child workflow types with the harness, which resolves them by name:

```php
$env = WorkflowTestEnvironment::inMemory(['work' => $spy]);
$env->registerWorkflow('Child', fn (array $input) => fn (WorkflowEnvironment $wf) => /* … */);

$result = $env->run(
    fn (WorkflowEnvironment $wf) => $wf->await($wf->childWorkflowStub(ChildWorkflow::class)->run(21)),
    'parent-1',
);
```

To register a workflow class that carries the attributes, use `registerWorkflowClass()` instead.
