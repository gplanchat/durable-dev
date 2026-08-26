---
title: Testing workflows
weight: 40
---

# Testing workflows

Durable ships a **testing toolkit** so you can validate your workflows and activities with standard PHPUnit.
There are two entry points depending on whether you write framework-agnostic tests or Symfony bundle integration tests:

| Utility | Package | When to use |
|---|---|---|
| `DurableTestCase` + `ActivitySpy` + `WorkflowTestEnvironment` | `gplanchat/durable` | Pure unit / functional tests, no Symfony container. |
| `DurableBundleTestTrait` | `gplanchat/durable-bundle` | Symfony `KernelTestCase`-based integration tests. |

---

## Unit and functional tests — `DurableTestCase`

`DurableTestCase` is an abstract PHPUnit `TestCase` that wires an **in-memory backend** for you.
Subclass it, call `createWorkflowTestEnvironment()`, run your workflow, and use the built-in assertions.

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

        // 3. Run the workflow body as a closure. This is the harness's own shape, not
        //    your workflow's: a real workflow takes WorkflowEnvironment in its
        //    constructor and business arguments in its #[WorkflowMethod].
        $result = $env->run(function (WorkflowEnvironment $wf) {
            return $wf->await($wf->activity('greet', ['name' => 'Alice']));
        }, $executionId = 'exec-greet-001');

        // 4. Assert the result and verify the activity was called.
        self::assertSame('Hello, Alice!', $result);
        $greetSpy->assertCalledTimes(1);
        $greetSpy->assertCalledWith(['name' => 'Alice']);

        // 5. Assert event-store invariants (optional, for deeper coverage).
        $this->assertWorkflowCompleted($executionId, 'Hello, Alice!');
        $this->assertActivityExecuted($executionId, 'greet');
    }
}
```

### Available assertions in `DurableTestCase`

| Method | Description |
|---|---|
| `assertWorkflowCompleted($executionId, $expected)` | The workflow reached `ExecutionCompleted` with the given result. |
| `assertWorkflowFailed($executionId, $class = '')` | The workflow reached `WorkflowExecutionFailed`, optionally with a specific exception class. |
| `assertActivityExecuted($executionId, $name)` | An `ActivityScheduled` event with that name exists in the journal. |
| `assertEventStoreContains($executionId, $class)` | Any event of the given class is present for this execution. |
| `countActivityExecutions($executionId, $name)` | Returns how many times a named activity was scheduled. |

---

## Controlling activity behaviour — `ActivitySpy`

`ActivitySpy` is a **callable test double** for activities. You can preset its return value, make it throw, or give it a sequence of results to simulate retries.

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
If a `\Throwable` appears in the sequence, it is **thrown** on that attempt.
The last entry is repeated once the sequence is exhausted.

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

## Low-level environment — `WorkflowTestEnvironment`

`WorkflowTestEnvironment` is the backing object that `DurableTestCase` uses. You can use it directly when you do not want to subclass `DurableTestCase`, for instance in test-support helper classes.

```php
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;

$env = WorkflowTestEnvironment::inMemory(['my-activity' => fn(array $p) => strtoupper($p['text'])]);

$result = $env->run(function (WorkflowEnvironment $wf) {
    return $wf->await($wf->activity('my-activity', ['text' => 'hello']));
}, 'exec-001');

assert($result === 'HELLO');
```

`WorkflowTestEnvironment` exposes:

- `run(callable $workflow, string $executionId): mixed` — run the workflow closure.
- `getEventStore(): EventStoreInterface` — read the in-memory event store.
- `getRunner(): InMemoryWorkflowRunner` — access the underlying runner directly.
- `getActivityTransport()` — inspect the in-memory activity queue.

---

## Symfony integration tests — `DurableBundleTestTrait`

For tests that boot your Symfony application kernel, use `DurableBundleTestTrait` in any class that extends `KernelTestCase`. The trait assumes that your **Messenger transports** in the `test` environment are configured as **in-memory** (see [Getting started](../getting-started/)).

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

In `config/packages/messenger.yaml` (under `when@test:`), ensure you have in-memory transports with names matching `DurableBundleTestTrait::$durableWorkflowTransports`:

```yaml
when@test:
    framework:
        messenger:
            transports:
                durable_workflows:  'in-memory://'
                durable_activities: 'in-memory://'
```

### Customising the transport list or drain timeout

Override the static properties before each test:

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

Unit tests check that the bridge builds well-formed protobuf commands. Only a real server tells you
they are **accepted**.

```bash
temporal server start-dev --namespace durable-test --port 7233

DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

Without `DURABLE_TEMPORAL_ADDRESS` the suite is skipped, so it stays harmless in a pipeline that has
no server.

Two workers run in **separate processes**, as in production. Both roles long-poll for tens of
seconds; alternating them in one process starves whichever is not currently polling.

Some tests need namespace-level setup, documented at the top of the file that needs it:

```bash
temporal operator search-attribute create --name DurableOrderId --type Keyword
temporal operator search-attribute create --name DurableAmount  --type Int
```

---

## Time is skipped, not waited for

A workflow that sleeps is testable in milliseconds. The harness runs on a **virtual clock** it
advances to the next due timer, so `sleep(Duration::hours(24))` costs no real time:

```php
$result = $env->run(function (WorkflowEnvironment $wf): string {
    $wf->sleep(Duration::hours(1));
    $answer = $wf->await($wf->activity('ping', []));
    $wf->sleep(Duration::hours(24));

    return $answer;
}, 'nightly-1');
```

The clock only moves when **nothing else can progress**. Skipping earlier would make the timer win
every `any(activity, timer)` race that an activity was about to win — so a race behaves the same
here as it does in production.

Retry backoff is a different matter: it uses real time, because a retry is queued on the transport
rather than recorded as a timer. Pass `initialInterval: Duration::zero()` to keep those tests fast.

---

## Two traps of the in-memory runner

**An execution that cannot progress fails instead of hanging.** A workflow waiting on a signal you
forgot to deliver raises `WorkflowStuckException` rather than spinning.

**Attempts are unlimited by default.** An activity that always fails retries forever, so the runner
enforces an overall budget and then reports which of the two situations you are in:

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

Retry backoff is honoured for real, so an activity configured with the default one-second interval
makes the test wait. Pass `initialInterval: Duration::zero()` to keep tests fast.

---

## Testing child workflows

The harness needs the child types registered, since it must resolve them by name:

```php
$env = WorkflowTestEnvironment::inMemory(['work' => $spy]);
$env->registerWorkflow('Child', fn (array $input) => fn (WorkflowEnvironment $wf) => /* … */);

$result = $env->run(
    fn (WorkflowEnvironment $wf) => $wf->executeChildWorkflow('Child', ['value' => 21]),
    'parent-1',
);
```

`registerWorkflowClass()` takes an attribute-annotated workflow class instead.
