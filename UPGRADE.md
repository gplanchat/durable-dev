# Upgrading

Every public break in this repository comes with its migration procedure: **Rector first, a script
when Rector cannot, and documentation in every case.** This file is the third half — it says what
moved, and what puts it right.

```bash
composer require --dev gplanchat/durable-rector
```

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()   // without it the rewritten names arrive fully qualified, next to a stale `use`
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/durable-upgrade.php']);
```

```bash
vendor/bin/rector process src
```

The set is **cumulative**: running it once catches up every version crossed at once. It contains
only what Rector can do without guessing; everything else is written by hand below.

## Unreleased

### An inline child starts at its parent's virtual time in the in-memory runner (#652)

`InMemoryWorkflowRunner` (under `WorkflowTestEnvironment`, `DurableTestCase` and Magento's memory
backend) started a child's virtual time at the real now. After the parent had skipped ahead to a
timer, the child's instants (timer due times, `first_queued_at`) lagged behind the parent's. The
child now starts at the parent's virtual now, and its activity queue keeps the transport's clock.
`InMemoryWorkflowRunner` gains an optional last argument `?ClockInterface $virtualTimeStartsAt`,
and `ChildWorkflowRunner` an optional last argument `?ClockInterface $queueClock`. Nothing to
migrate.

### A workflow goes on after a timer beats a retrying activity in `any()` (#678)

On the event-store backends (in-memory, DBAL, Illuminate, Magento), an activity cancelled because
it lost a race (`ActivityCancelled` with reason `race_superseded`) now reads back on replay as
unsettled, as a losing timer already did. It used to read back as a rejection. Because it was
the first member of `any()` to settle, the next resume failed with `Workflow did not handle
superseded activity`. Replay cancels the loser again, and the journal records that cancellation
once, not once per resume. A later outcome recorded for the cancelled activity is ignored.
Nothing to migrate: journals written before this change replay under the new rule.

### The ports take an `ExecutionId`, not a string (#638)

**Who is affected**: an application that calls one of the eight ports below with a string execution
id, reads an id one of them returns, or implements one of them, such as a custom store, dispatcher,
catalog or Temporal client double. The database is untouched: every store writes `toString()` to the
same `execution_id` columns and reads them back with `ExecutionId::fromString()`.

| Port                                                    | Method                                                                  | Changes                                          |
|---------------------------------------------------------|-------------------------------------------------------------------------|--------------------------------------------------|
| `Store\EventStoreInterface`                             | `readStream()`, `readStreamWithRecordedAt()`, `countEventsInStream()`   | argument                                         |
| `Store\WorkflowMetadataStore`                           | `save()`, `markCompleted()`, `get()`, `hasActiveWorkflowMetadata()`, `delete()` | argument                                 |
| `Port\WorkflowRunCatalogInterface`                      | `findRun()`                                                             | argument                                         |
| `Store\ChildWorkflowParentLinkStoreInterface`           | `link()` (both), `unlink()`                                             | arguments                                        |
|                                                         | `getParentExecutionId()`                                                | argument; returns `?ExecutionId`                 |
|                                                         | `getChildExecutionIdsForParent()`                                       | argument; returns `list<ExecutionId>`            |
| `Port\WorkflowResumeDispatcher`                         | `dispatchResume()`, `dispatchResumeAwaiting()`, `dispatchNewWorkflowRun()` | argument                                      |
| `Port\WorkflowHistorySourceInterface`                   | `hasChildExecutionId()`, `hasChildExecutionCompletedSuccessfully()`     | argument                                         |
|                                                         | `findScheduledChildExecutionId()`                                       | returns `?ExecutionId`                           |
| `Port\WorkflowBackendInterface`                         | `start()`                                                               | argument                                         |
| `Bridge\Temporal\WorkflowClientInterface`               | `startSync()`, `workflowId()`                                           | argument                                         |
|                                                         | `startAsync()`                                                          | argument; returns `ExecutionId` (see below)      |

`signal()`, `query()`, `update()` and `pollForCompletion()` on the Temporal client keep their string
ids: the first three take a Temporal workflow id, not an execution id. `WorkflowClient::startCron()`
keeps its signature and still returns the workflow id. The conformance suites under
`Gplanchat\Durable\Testing` pass the value object too, so a custom store that runs them has to be
migrated first.

**`startAsync()` changes what it returns, not only its type.** It used to return the Temporal
workflow id it started the run under. It now returns the execution id it was given. The Temporal id
is `workflowId($executionId)`. Passing the result straight to `signal()`, `query()` or `update()` is
now a `TypeError` under `strict_types`. Without `strict_types`, PHP turns the object into the
execution id string, and the call reaches a workflow id that does not exist.

```php
// before
$workflowId = $client->startAsync('Order', $input, 'order-42');
$client->signal($workflowId, 'paid');

// after
$executionId = $client->startAsync('Order', $input, ExecutionId::fromString('order-42'));
$client->signal($client->workflowId($executionId), 'paid');
```

**Rector does the calling side it can prove.** The `durable-upgrade` set carries
`ExecutionIdArgumentRector`. On a call whose receiver is typed as one of these ports, or as a class
implementing one, it wraps an argument typed `string` in `ExecutionId::fromString()`, including
through `?->`. It leaves alone:

- a receiver it cannot type, such as `$container->get(EventStoreInterface::class)->readStream($id)`
  or Laravel's `app(...)`. Static analysis may miss these too, and the `TypeError` shows up at run time;
- a named or unpacked argument, a nullable one (`?string`), and one whose type is `mixed` or
  unknown;
- any code that **reads** an id a port returns (`getParentExecutionId()`,
  `getChildExecutionIdsForParent()`, `findScheduledChildExecutionId()`, `startAsync()`), including
  the `$client->signal($client->startAsync(...), ...)` pattern. What the value is for decides the
  rewrite (`->toString()` for a string, `workflowId()` for a Temporal id, or keep the value object),
  and no rule can guess it;
- every call made inside a class that **implements** a port, including a decorator's
  `$this->inner->readStream($executionId)` and its calls to itself. Step 4 turns those parameters
  into `ExecutionId`, and a wrap written before would then be `fromString()` of an object.
  Changing a parameter type makes the method body wrong wherever it relies on a string, which is a
  review, not a rewrite.

**What to do**, in this order:

1. Run the `durable-upgrade` set, then PHPStan or Psalm: what remains is listed.
2. At each call Rector left, pass `ExecutionId::fromString($id)`. An empty string is refused:
   `fromString('')` throws, where a store used to look up the empty id and find nothing. The
   helpers that kept a `string` signature convert inside, so they refuse `''` too, for example
   `WorkflowQueryEvaluator`, `ActivityEventJournal`, `RunDashboard::run()`, the message handlers.
3. Where you read a returned id, call `->toString()` where a string is needed, and compare two ids
   with `->equals()`, not `===`. After `startAsync()`, call `workflowId()` for the Temporal id.
4. **In a class that implements a port**, change each listed parameter to `ExecutionId`. Call
   `->toString()` where the body stores or compares the id, and pass the object through unchanged to
   another port. Change the four return types in the table. A stored id comes back through
   `ExecutionId::fromString()`. An implementation of `WorkflowClientInterface::startAsync()` returns
   the `ExecutionId` it was given, no longer the workflow id it started.

### In-memory runner: a timer due during an activity's backoff wins `any()` (#653)

**Who is affected**: a workflow run by `InMemoryWorkflowRunner` (under `WorkflowTestEnvironment`,
`DurableTestCase` and Magento's memory backend) that races a retrying activity against a timer.

This replaces the 0.1.0-beta1 note on timers that fall due during a backoff, in the PSR-20 clock
section (#617).

The drain used to run every remaining attempt of a retrying activity before it fired a timer, so
in `any(activity, timer)` the activity won even when the timer fell due during its backoff. The
drain now waits only until that timer is due, fires it and resumes the workflow before the next
attempt. The timer wins, as it does on Temporal, the activity is cancelled and its queued retry
removed: the history reads `TimerCompleted ActivityCancelled`, where it read
`ActivityCompleted TimerCompleted`.

One difference with Temporal remains. On Temporal, the server's clock runs during an attempt, so
a timer shorter than a slow attempt that succeeds wins. Here an attempt that is running is never
cut short, and the virtual clock never follows the wall clock on its own: that timer does not
win, and the history reads `ActivityCompleted TimerCancelled`.

**What to do**: a test that expected the retrying activity to win now sees the timer win, as it
would on Temporal. Update its expectation. No Rector rule: this is a behaviour change, not an
API change.

### `durable.temporal.dsn: '%env(…)%'` compiles again without an `env()` default (#687)

**Who is affected**: every Symfony application that names its DSN through an environment variable
and declares no `env(…)` parameter for it, since 0.1.0-beta1. Its container no longer compiled:
"Invalid configuration for path "durable.temporal.dsn": A temporal://… DSN string is expected,
got """. Symfony checks such a placeholder with an empty string at compile time, and the rule
that refuses an empty DSN (#334) refused the placeholder too.

The rule now lets a placeholder through, and still refuses a literal empty or blank DSN and a value
that is not a string. **What to do**: nothing. An `env(…)` default added as a workaround can stay.
One case is stricter than before: an empty DSN is refused in whichever file writes it, even if a
later profile sets a real one. It was accepted when the merged value was the only one checked.

### Temporal: a workflow goes on after a timer beats an activity in `any()` (#681)

The rule of #678, on Temporal. An activity whose cancellation the workflow requested now reads
back on replay as unsettled, unless the workflow's own cancellation withdrew it (that one still
replays as `WorkflowCancelledFailure`). It used to read back as a rejection, so the run failed
with `Activity <id> was superseded (Cancelled by Temporal)`. Replay no longer sends
`RequestCancelActivityTask` again for an activity whose `ACTIVITY_TASK_CANCEL_REQUESTED` is in the
history, and an outcome the activity records after that request is ignored. Nothing to migrate.

### `WorkflowServiceClientInterface` gains `DescribeTaskQueue()`

**Who is affected**: only whoever **implements** `WorkflowServiceClientInterface` without extending
`AbstractWorkflowServiceClient`. The bundled transports extend it and have nothing to change.

**Why.** It is how the bridge learns whether a worker polls a task queue: an absent worker
otherwise shows only once an execution has stalled.

**What to write.** Forward the call like the other RPCs, or extend `AbstractWorkflowServiceClient`,
which inherits it from `WorkflowRpcMethods`. Both message classes are in `Temporal\Api\Workflowservice\V1`:

```php
public function DescribeTaskQueue(DescribeTaskQueueRequest $request, array $metadata = [], array $options = []): DescribeTaskQueueResponse
{
    return $this->call(__FUNCTION__, $request, DescribeTaskQueueResponse::class, $metadata, $options);
}
```

### Laravel: an unserved Nexus operation and a missing workflow class now fail at registration (#714)

**Who is affected**: a Laravel application that serves Nexus (`nexus.handlers`, `backend:
temporal`). Resolving `NexusOperationRegistry` used to succeed in four cases where it now throws
`InvalidArgumentException`, naming what is wrong:

- an operation of a declared contract that neither a handler method nor a workflow carrying
  `#[FulfilsNexusOperation]` serves. It used to be skipped, so a caller waited on a result nothing
  produced. Symfony's `NexusHandlerPass` already refused it at compile time.
- a class in `workflows` that does not exist. It used to be skipped while looking for the
  operations workflows fulfil.
- a `handler => contract` entry whose handler class does not exist. It used to boot as long as
  workflows fulfilled some of the contract's operations.
- a `handler => contract` entry whose handler carries an `#[AsNexusServiceHandler]` naming a
  different contract.

**What to do**: give the handler a method for the operation, or list the workflow that fulfils it
in `workflows`. Fix or remove a misspelt workflow or handler class. Make the entry's contract and the
attribute's agree, or list the handler alone so that the attribute names its contract.

`nexus.handlers` also accepts a handler class on its own, whose contract its
`#[AsNexusServiceHandler]` names: `'handlers' => [App\Nexus\BillingHandler::class]`. The
`handler => contract` form keeps working, and is refused if it names another contract than the
attribute.

### The runtime ports take an `ExecutionId` too: projections, observers, lifecycle, transport (#682)

**Who is affected**: an application that calls one of the thirteen interfaces below with a string
execution id, or implements one of them: a custom run catalog or projection, a profiler or dispatch
observer, an activity transport, a timer dispatcher, an attempt claim, a fenced event store, a
lifecycle, a command buffer or a child runner, including a test double. This continues #638 on the
next ring of ports. Nothing stored or sent changes: every implementation writes `toString()` to the
same columns, lock keys, array keys and wire messages as before.

| Interface                                            | Method                                                                   | Changes                        |
|------------------------------------------------------|--------------------------------------------------------------------------|--------------------------------|
| `Observation\WorkflowRunProjectionInterface`         | `recordStart()`, `recordOutcome()`                                        | argument                       |
| `Observation\WorkflowRunWaitProjectionInterface`     | `recordWait()`                                                            | argument                       |
| `Observation\WorkflowRunPickupProjectionInterface`   | `recordPickup()`                                                          | argument                       |
| `Debug\WorkflowDispatchObserverInterface`            | `onWorkflowDispatchRequested()`                                           | argument                       |
| `Debug\WorkflowExecutionObserverInterface`           | `onWorkflowRun()`, `onActivityExecuted()`                                 | argument                       |
| `Transport\ActivityTransportInterface`               | `removePendingFor()`                                                      | argument                       |
| `Port\ParentChildWorkflowCoordinatorInterface`       | `onParentClosed()`                                                        | argument                       |
| `Port\WorkflowLifecycleInterface`                    | `onBeforeRun()`, `isCancellationPending()`, `onCancellationDelivered()`, `onCancelled()`, `onCompleted()`, `onSuspended()`, `onContinuedAsNew()`, `onFailed()` | argument |
| `Port\WorkflowTimerDispatcher`                       | `dispatchTimerFire()`                                                     | argument                       |
| `Port\WorkflowCommandBufferInterface`                | `scheduleChildWorkflow()`, `completeChildWorkflow()`, `failChildWorkflow()` | child id argument            |
| `Port\ChildWorkflowRunnerInterface`                  | `runChild()`                                                              | child id; parent `?ExecutionId` |
| `Port\ActivityAttemptClaimInterface`                 | `claim()`                                                                 | argument                       |
| `Store\FencedEventStoreInterface`                    | `claimPass()`                                                             | argument                       |

`WorkflowRunDescription::$runId` stays a `string`: it is the backend's own run id, possibly
sanitised, not an id a port accepts. `PassFence` keeps its string id. The events, `ExecutionContext`
and the public helpers still carry a string; they follow in the next parts of #682.

**Rector does the calling side it can prove.** `ExecutionIdArgumentRector`, in the `durable-upgrade`
set, now knows these thirteen interfaces. It wraps a `string` argument in `ExecutionId::fromString()`
on a receiver typed as one of them, with the same limits as for #638: it skips an untyped receiver,
a named, unpacked or nullable argument, and every call made inside a class that implements any port
it knows. That last rule now covers more classes: a custom timer dispatcher or projection is
migrated by hand, calls to the other ports included.

**What to do**, in this order:

1. Run the `durable-upgrade` set, then PHPStan or Psalm, and pass `ExecutionId::fromString($id)` at
   each call left. An empty string is refused, including by `WorkflowFiberDriver::run()` and
   `PassEventStore::open()` (over any store), which keep a `string` parameter for now and convert
   on entry.
2. **In a class that implements one of these interfaces**, change each listed parameter to
   `ExecutionId` (`?ExecutionId` for the parent of `runChild()`). Call `->toString()` where the
   body stores, binds, formats, serialises or compares the id. `json_encode()` turns the object
   into `{}`, and `===` or a strict `in_array()` against a string is always false; an array key
   fails loudly with a `TypeError`. Pass the object on unchanged to another port.
3. A test double that records the ids it heard can record `->toString()` and keep its assertions.

### Magento: `#[AsActivityHandler(contract)]` narrows what a handler serves (#715)

**Who is affected**: only a handler declared in `di.xml` that carries `#[AsActivityHandler]` **and**
implements more than one `#[AsActivity]` interface. Magento used to serve every such interface and
ignored the attribute; it now serves only the named `contract`, as Symfony always did. A handler
whose class lacks a method of the named contract is refused by name when the runtime is built.

**What to write.** Nothing, if the named contract is the one you meant. If you relied on the other
interfaces being served, drop the attribute (the interfaces then drive, as before) or move them to
a handler of their own.

### Laravel: the shipped migrations run on `durable.connection`

**Who is affected**: a Laravel application whose `config/durable.php` names a `connection` other
than the default one. `php artisan migrate` used to build Durable's tables on the default connection;
the stores created their own copies on `durable.connection` at the first write, and later schema
migrations never reached those. With `connection => null`, nothing changes.

**What to do**:

1. Run `php artisan migrate`. Migrations not yet run now land on `durable.connection`.
2. Tables the stores created before a later schema change may lack it: `picked_up_at`,
   `waiting_on`, the status index, `durable_execution_heads`. The four migrations that bring them
   check before they alter, so running them on that connection is safe. The only rows they touch
   are those of a missing `picked_up_at`, filled from `started_at`. They alter tables that must
   exist: if the journal's database has none yet, run `php artisan migrate` first (step 1).
   Then:

   ```bash
   php artisan migrate --database=<connection> \
     --path=vendor/gplanchat/durable-bridge-illuminate/Migrations/2026_09_24_000000_add_picked_up_at_to_durable_workflow_runs.php \
     --path=vendor/gplanchat/durable-bridge-illuminate/Migrations/2026_09_24_000001_add_waiting_on_to_durable_workflow_runs.php \
     --path=vendor/gplanchat/durable-bridge-illuminate/Migrations/2026_09_25_000000_add_status_index_to_durable_workflow_runs.php \
     --path=vendor/gplanchat/durable-bridge-illuminate/Migrations/2026_09_28_000000_create_durable_execution_heads.php
   ```

   `--database` also puts a `migrations` table on that connection, to record them.
3. Drop the empty copies left on the default connection, if any.
4. A copy published with `vendor:publish --tag=durable-migrations` belongs to the application and
   keeps running on the default connection: make it extend
   `Gplanchat\Bridge\Illuminate\Schema\DurableMigration` instead of
   `Illuminate\Database\Migrations\Migration`.

Recommending a connection of its own is **DUR054**.

### A warning when the journal is on the application's default connection

**Who is affected**: a Symfony application whose `durable.dbal.connection` is the default Doctrine
connection (the default setting), and a Laravel application on the `illuminate` backend whose
`durable.connection` is unset or names the default connection. Symfony logs a warning when a worker
starts; Laravel logs one at boot, in the console only.

**What to do**: nothing is required, and nothing is refused. To act on it, give the journal a
connection of its own, as the configuration examples show (**DUR054**).

### Laravel `illuminate` backend: a due timer fires (#726)

`LaravelWorkflowTimerDispatcher` now queues a `FireWorkflowTimersJob`, which runs
`FireWorkflowTimersHandler`, instead of a plain `ResumeWorkflowJob`. A plain resume never journalled
`TimerCompleted`, so a run that slept suspended again on every pass and never woke up. Nothing to
migrate: once `queue:work` restarts on the new code, a run stuck on a due timer wakes on its next
resume, since the pass that suspends on the timer now queues the firing.


### New: a Magento module serves Nexus operations (#668)

**Who is affected**: nobody has to change anything. A Magento module can now serve a Nexus contract:
list the handler in `di.xml` under `nexusHandlers` on `RuntimeFactory`, name its contract with
`#[AsNexusServiceHandler(contract: …)]` as on Symfony, declare the workflows that fulfil the rest in
`workflowClasses` with `#[FulfilsNexusOperation]`, and run `bin/magento durable:worker --role=nexus`.
The module's README shows it. Laravel's `DeclaredNexusOperations` now delegates to the core's
`NexusHandlerDeclarations`, which both hosts share, so a module gets the refusals of #714 above:
an operation nobody serves, a workflow class that does not exist, or a contract the attribute
contradicts stops the Nexus worker when it starts.

### Temporal read model: a cancelled activity or timer says why (#701)

Read through `TemporalReadThroughEventStore` (the bundle's event store on Temporal, the profiler,
the dashboards), `ActivityCancelled` and `TimerCancelled` used to carry the reason
`Cancelled by Temporal`. They now carry the reason the event-store backends record:
`workflow_cancelled` when the workflow's own cancellation withdrew the operation, `race_superseded`
otherwise. A replay through that store now reads a race loser as unsettled, as the worker does.
Code that matched on `Cancelled by Temporal` should match on `ActivityCancellationReason` instead.
Code that converts a history itself should build the converter with
`TemporalEventConverter::forHistory($executionId, $events)` rather than `new TemporalEventConverter()`:
a converter built with `new` only knows the markers it has already seen, and reads a
workflow-cancelled operation that was cancelled before its marker as `race_superseded`.

### Laravel: activity handlers are declared in `activity_handlers` (#713)

`config/durable.php` gains an `activity_handlers` key beside `workflows`. Each class listed there
serves the contract its `#[AsActivityHandler]` names, or else every interface it implements whose
methods carry `#[AsActivityMethod]`, under the activity names the contract carries. A handler is
resolved from the container each time one of its activities runs: bind it as a singleton to share
one instance across a worker's tasks. A class that does not exist, that
serves no activity, or that lacks a method of the contract it names is refused by name at boot.

**What to do**: nothing, unless you registered activities by hand. Replace calls such as
`$app->make(RegistryActivityExecutor::class)->register('greet.hello', ...)` with the handler class
in the key:

```php
'activity_handlers' => [App\Activities\Greeter::class],
```

A direct `register()` still works and wins over a declared handler of the same name.

### Journal events are built from an `ExecutionId`, and `Event::executionId()` returns one (#682)

**Who is affected**: code that builds a journal event, such as a custom command buffer, a test or a
fixture that appends events, or a custom `Event` class. Also code that reads `executionId()` from an
event. **Nothing stored or sent changes.** `EventDataMapper::fromDomainEvent()` still writes the
string under `execution_id`, the Temporal activity input still carries it as a string, payloads hold
only plain data, and `InMemoryEventStore` still files each stream under the string.

| Where                                                   | Changes                                                        |
|---------------------------------------------------------|----------------------------------------------------------------|
| `Event\Event::executionId()`                            | returns `ExecutionId`                                          |
| The constructor of every class in `Gplanchat\Durable\Event` | first argument `ExecutionId` (the parent id for the three `ChildWorkflow*` events) |
| `WorkflowExecutionFailed::fromStoredPayload()`, `unhandled…()`, `deadlineExceeded()`, `workflowHandlerFailure()`, `terminatedByParent()` | first argument `ExecutionId` |
| `ActivityCatastrophicFailure::fromStoredPayload()`, `forThrowable()`; `ActivityTaskFailed::forThrowable()`; `ActivityFailed::fromEnvelope()` | first argument `ExecutionId` |
| `Failure\WorkflowFailureClassifier::classify()`, `Failure\ActivityFailureEventFactory::fromActivityThrowable()` | first argument `ExecutionId` |

The other ids an event carries keep their string type for now: the child id of the `ChildWorkflow*`
events, `WorkflowCancellationRequested::sourceParentExecutionId()`, the next id of
`WorkflowContinuedAsNew`, and the parent id of `terminatedByParent()`. They became `ExecutionId`s too:
see the section on the other ids an event carries, below.

**Reading back is stricter in one case.** `EventDataMapper::toDomainEvent()` converts the stored
`execution_id` with `ExecutionId::fromString()`, so a row stored with an empty id now throws
`InvalidArgumentException` instead of producing an event for the empty execution. A store only holds
such a row if a run was started under an empty id.

**Rector does the building side it can prove.** The `durable-upgrade` set carries a new rule,
`ExecutionIdEventArgumentRector`. It wraps the first argument in `ExecutionId::fromString()` in
`new <Event>(...)`, for any class that implements `Event`, and in the static factories listed
above, when that argument is typed `string` and the parameter it reaches is typed `ExecutionId`.
A custom event whose constructor still takes a string is left alone until you retype it. It also
leaves a named, unpacked, nullable, `mixed` or unknown argument alone, and it does not touch code
that reads `executionId()`.

**What to do**, in this order:

1. **In a custom `Event` class**, type the constructor's id `ExecutionId` and return it from
   `executionId()`. If you map it to storage yourself, write `->toString()`, not the object.
2. Run the `durable-upgrade` set, then PHPStan or Psalm, and wrap each id left in
   `ExecutionId::fromString()`. An empty string is refused.
3. Where you read `$event->executionId()`, call `->toString()` when a string is needed: an array
   key, a JSON field, a log line or a comparison with a string. `json_encode()` turns the object
   into `{}`, and `===` against a string is always false. Compare two ids with `->equals()`.
4. In a test, compare ids with `->toString()` or `->equals()`, not `assertSame()` on the objects:
   two value objects with the same id are equal, not identical.

### Static helper classes can no longer be instantiated

Classes that hold no state and only offer static methods now have a private constructor.
`new ReadableDuration()` and the like used to succeed and gave an object with nothing to call on
it. It now fails with `Error: Call to private ... ::__construct()`. Nothing in this repository
instantiated them.

- **Core:** `ActivityEventJournal`, `ActivityFailureEventFactory`,
  `AsyncChildWorkflowFailureProjector`, `EventDataMapper`, `JournalAssertions`,
  `NexusFulfilmentParameterNames`, `PendingTimers`, `ReadableDuration`, `RecordedDetails`,
  `StoredTimestamp`, `TimerWakeDelayCalculator`, `WaitReason`, `WorkflowQueryEvaluator`.
- **Temporal bridge:** `DurableSearchAttributes`, `GrpcUnary`, `JournalExecutionIdResolver`,
  `JsonPlainPayload`, `TemporalActivityScheduleInput`.
- **Symfony bundle:** the six `DependencyInjection\Loader` classes, `DurableProfilerEventPresentation`
  and `DurableProfilerTimeframe`. `DbalStores::registerDbalRunCatalog()` and
  `EventStores::registerTemporalEventStore()` are now private; only their own class called them.
- **Filament:** `StatusColor`.

Some of these classes are also tagged `@internal`: they are plumbing, not API, and may change in a
minor release. Psalm reports a use from outside the `Gplanchat\` namespace. The tagged
ones are `ActivityFailureEventFactory`, `AsyncChildWorkflowFailureProjector`,
`AwaitableCancellation`, `AwaitableInspector`, `PendingTimers`, `StubArguments`,
`WorkflowFailureClassifier`, the Temporal bridge's `GrpcWire`, `JsonGatewayRequest`,
`TemporalActivityScheduleInput`, `TemporalPolicyMapper` and `UpdateProtocol`, the profiler's two
classes, and `StatusColor`.

**What to do:** call the static methods on the class, for example `ReadableDuration::of($seconds)`.
If you used a class that is now `@internal`, open an issue describing the use: it tells us which
part of it should become API.

### DBAL and Illuminate stores: a whole-valued float reads back as a float (#759)

The DBAL and Illuminate event stores and workflow metadata stores now encode payloads with
`JSON_PRESERVE_ZERO_FRACTION`. A `30.0` written in an event payload, an activity result or a
side effect used to read back as the int `30`. It now reads back as `30.0`, as the in-memory store
returns it.

**What to do:** nothing. Rows written before this change still read back as ints, and replaying
them does not diverge: the replay guard compares `30` and `30.0` as equal. Code that received an
int from those rows and branched on `is_int()` sees a float from new rows.

### `JournalAssertions::assertWorkflowFailed()` takes a `class-string<\Throwable>` (#800)

Its third parameter is now typed `class-string<\Throwable>|''`, as it already was on
`DurableTestCase::assertWorkflowFailed()` and `DurableBundleTestTrait::assertWorkflowFailed()`.
Nothing changes at runtime. PHPStan and Psalm now report a call with a class that does not exist
or is not a `Throwable`.

**What to do:** pass `SomeException::class` rather than a string literal, and fix any name the
analyser reports.

### Magento grid: the text filters work, on the whole workflow name and the start of an id (#815)

The workflow name, execution id and backend run id filters of the process history grid matched
nothing in a real admin. They declared the `text` shorthand, which Magento turns into a `like`
condition and a `%text%` pattern before the data provider sees it, and the provider compared that
pattern with the value. They now declare an `eq` condition, so the text arrives as typed, and they
follow the rule of the Sylius and Filament lists: the whole workflow name, and the start of the
execution id or of the run id, all as typed, with `%` and `_` as ordinary characters.

**What to do:** nothing in your code. Operators type the whole workflow name, or the start of an id.
### Dashboards: every event of the run carries the workflow's name (#850)

On every backend, the events of the run's own line (its end, its failure, its cancellation) now
carry the workflow's name, as the follow-ups of an activity carry the activity's name. The phase
says what happened. They used to carry the event class, such as `WorkflowExecutionFailed` or
`WORKFLOW EXECUTION FAILED`. On Temporal, the memo the worker writes at each suspension
(`WORKFLOW PROPERTIES MODIFIED`) also joins the run's line instead of drawing a line of its own.

**What to do:** nothing, unless a check of your own reads `WorkflowRunEvent::$label` and expects
an event class there. Read `$phase` instead.

### The other ids an event carries, the pass and `WorkflowEnvironment::executionId()` are `ExecutionId`s (#682)

**Who is affected**: workflow code that reads `$env->executionId()`, code that reads the child,
parent or next id of a journal event, and code that builds an `ExecutionContext`, a command buffer
or a `TemporalEventConverter` itself, such as a test harness. **Nothing stored or sent changes.**
Each payload still writes these ids as strings, and the Temporal memo and search attributes still
carry the string. `TheJournalKeepsItsStringIdsTest` pins the payloads, and the Temporal command
buffer's tests pin the memo.

| Where                                                                   | Changes                              |
|-------------------------------------------------------------------------|--------------------------------------|
| `WorkflowEnvironment::executionId()`, `ExecutionContext::executionId()` | return `ExecutionId`                 |
| `ChildWorkflowScheduled`, `ChildWorkflowCompleted`, `ChildWorkflowFailed`: second constructor argument, `childExecutionId()` | `ExecutionId` |
| `WorkflowCancellationRequested`, `WorkflowExecutionCancelled`: `$sourceParentExecutionId`, `sourceParentExecutionId()` | `?ExecutionId` |
| `WorkflowContinuedAsNew`: `$newExecutionId`, `newExecutionId()`         | `?ExecutionId`                       |
| `WorkflowExecutionFailed::terminatedByParent()`                         | the parent id is an `ExecutionId`    |
| The constructors of `ExecutionContext`, `EventStoreCommandBuffer`, `TemporalWorkflowCommandBuffer` and `TemporalEventConverter`, and `TemporalEventConverter::forHistory()` | take `ExecutionId` |
| `TemporalExecutionHistory::waitJournal()`                               | takes `ExecutionId`                  |
| `AwaitedFact::isJournalledIn()`                                         | the journal's id is an `ExecutionId`; the fact itself keeps its string ids, since it travels in the resume message |

These keep a string for now, and a later part of #682 moves most of them:

- `WorkflowFiberDriver::run()`, `PassEventStore::open()`, the `EventStoreHistorySource`
  constructor, `ExecutionEngine::start()` and `resume()`, `InMemoryWorkflowRunner::run()`;
- the public and testing helpers, among them `PendingTimers`, `WaitReason`,
  `ActivityEventJournal`, `WorkflowQueryEvaluator`, `JournalRunHistoryReader`, `RunDashboard`,
  `JournalAssertions`, `DurableTestCase` and `DurableBundleTestTrait`;
- `WorkflowRunDescription::$executionId`, `ContinueAsNewRequested::nextExecutionId`,
  `WorkflowCancelledFailure`, `ChildWorkflowOutcome` and `DurableChildWorkflowFailedException`;
- the wire messages, and the ids an `AwaitedFact` carries.

`WorkflowRunDescription::$runId` stays a string for good (decision on #682).

**Reading back is stricter.** `EventDataMapper::toDomainEvent()` converts the stored child id and
the next id with `ExecutionId::fromString()`, so an empty one now throws `InvalidArgumentException`.
An empty `sourceParentExecutionId` reads back as `null` on both cancellation events;
`WorkflowExecutionCancelled` used to keep the empty string. `TemporalEventConverter` refuses a child
event whose workflow id is empty.

A journal written by 0.1.0-beta1 can hold an empty child id in one case: a workflow started a child
with `ChildWorkflowOptions(workflowId: '')` on the local backend (in memory, DBAL or Illuminate).
Nothing checked that option, and `ChildWorkflowScheduled` is appended before the child runs, so the
parent's journal records `"childExecutionId":""`. Temporal refuses an empty workflow id, so its
histories cannot hold one. After the upgrade, every read of that parent's stream throws: the
replay, the dashboards, `durable:execution:diagnose` and the parent and child coordinator.

To find such a parent, look for the empty field in the stored payloads, for instance on DBAL:
`SELECT DISTINCT execution_id FROM durable_events WHERE payload LIKE '%"childExecutionId":""%'`.
Finish or cancel those runs before you upgrade, or remove their rows once they no longer matter.

**Rector does the building side.** In the `durable-upgrade` set:

- `ExecutionIdEventArgumentRector` now wraps **every** positional string argument whose parameter
  accepts an `ExecutionId`, not only the first one. It reaches the constructors and the factory
  in the table.
- `ExecutionIdArgumentRector` wraps the id passed to `TemporalExecutionHistory::waitJournal()` and
  `AwaitedFact::isJournalledIn()`.

It does not touch code that reads `executionId()`, `childExecutionId()`,
`sourceParentExecutionId()` or `newExecutionId()`: after the upgrade, such a call may already be
where an `ExecutionId` belongs.

**What to do**, in this order:

1. Run the `durable-upgrade` set, then PHPStan or Psalm, and wrap each id they report in
   `ExecutionId::fromString()`. An empty string is refused.
2. Look for these four getters in your code, workflow code first. Call `->toString()` wherever
   the value lands in an activity payload, a log context, an array key or a comparison with a
   string: `json_encode()` turns the object into `{}`, and `===` against a string is always false.
   Under `declare(strict_types=1)`, passing the object to a `string` parameter is a `TypeError`,
   even though `ExecutionId` is `Stringable`. Compare two ids with `->equals()`, and pass the
   object as it is to a port.

### Temporal: a whole-valued float reads back as a float (#826)

The Temporal bridge now encodes payloads with `JSON_PRESERVE_ZERO_FRACTION`, as the DBAL and
Illuminate stores do since #759. A `30.0` in an activity result, a side effect, a workflow input or
result, an update or a Nexus result used to read back as the int `30`. It now reads back as `30.0`.
A `Double` search attribute goes out as `30.0` instead of `30`; an `Int` one is unchanged.

**What to do:** nothing. Events recorded before this change keep their bytes and still read back as
ints, so replaying them gives the same values, and the replay guard compares `30` and `30.0` as
equal. Code that received an int from those payloads and branched on `is_int()` sees a float from
new events.

### Temporal: a child starts with its memo, summary and details (#804)

`ChildWorkflowOptions::$memo`, `$staticSummary` and `$staticDetails` now reach the
`StartChildWorkflowExecution` command: the memo as the child's memo, the summary and details as the
command's user metadata, which the Temporal UI shows. Before, the SQL and in-memory journals
recorded them and the Temporal bridge dropped them. The summary and details need Temporal Server
1.25 or later: an older server drops them without an error. The memo reaches every supported
server.

A child memo key `durableExecutionId` or `durableWaitingOn` now throws
`UnsupportedByBackendException` on Temporal: Durable writes both keys itself.

**What to do:** rename a child memo key if it is one of those two.

### Temporal: the workflow worker keeps polling after a decode failure or a rejected completion (#824, #840)

A payload that fails to decode on a later history page now fails the workflow task
(`RespondWorkflowTaskFailed`), as it already did on the first page. Outside a task poll, the codec
client throws `Gplanchat\Bridge\Temporal\Codec\PayloadDecodeFailure`, a `\RuntimeException` whose
previous exception is the codec's own error. A Nexus task whose payload fails to decode is
answered with a retryable `INTERNAL` handler error: the server delivers it again, and a worker
redeployed with the right codec or key serves it.

A `RespondWorkflowTaskCompleted` rejected with `INVALID_ARGUMENT` is logged as a warning and the
worker polls again. `WorkflowTaskProcessor` and `TemporalRuntimeAssembly` gain an optional last
argument `?LoggerInterface $logger`; the Symfony bundle, the Laravel provider and the Magento
runtime factory pass theirs. Nothing to migrate.

### `JournalRunHistoryReader::fromEntries()` (#819)

`JournalRunHistoryReader` gains a static `fromEntries(iterable $entries, string $workflowName = '')`.
It builds the same history as `read()` from journal entries you already read with
`readStreamWithRecordedAt()`. The profiler panel uses it to draw `RunTimeline` without a second
journal read. `read()` returns the same history as before.

**What to do:** nothing.

### Temporal: the workflow worker keeps polling after a rejected task answer (#863, #891)

A `RespondWorkflowTaskFailed` rejected with `NOT_FOUND` (the task has already timed out) or
`INVALID_ARGUMENT` no longer stops the worker: both are logged as a warning, with the gRPC code
and the server message, and the worker polls again. Any other gRPC error still propagates out of
`WorkflowTaskProcessor::processOne()`. A `RespondWorkflowTaskCompleted` rejected with `NOT_FOUND`
is now logged the same way (#891). Nothing to migrate.

### Magento: `MagentoRuntime::run()` follows the configured backend (#765)

With `durable/temporal/dsn` set in `app/etc/env.php`, `run()` used to execute the workflow in the
calling process, its activities included, and the cluster never saw it. It now starts the workflow
on the cluster with `workflowClient()->startAsync()` and waits for its result with
`pollForCompletion()`, as the Symfony bench does. Without a DSN, `run()` still executes in the
calling process.

With a DSN, four things differ from the in-process run:

- The journal and activity workers (`bin/magento durable:worker --role=journal` and
  `--role=activity`) carry the execution. Without them, `run()` throws `WorkflowStuckException` once
  `budgetSeconds` is spent.
- `maxActivityRetries` no longer applies: the cluster retries from each activity's own `RetryLimit`.
  `budgetSeconds` bounds the wait for the result, polled every 500 ms.
- A workflow that fails comes back with the exception the in-memory backend raises, as described in
  "Temporal: `pollForCompletion()` throws the exception the journal backends raise (#872)" below. A
  workflow that times out or is terminated throws `WorkflowTimedOutException` or
  `WorkflowTerminatedException`. A workflow that waits on a signal waits the whole budget instead
  of failing at once.
- The result comes back decoded from JSON: an object the workflow returns arrives as an array.

**What to do:** if your code relies on `run()` executing in the calling process while a DSN is set
(activities reading request state, a test without a cluster), keep the DSN out of that process's
`env.php`, or start the workers before calling `run()`. To start a workflow from a web request
without waiting, call `workflowClient()->startAsync()`.

### `WorkflowClient::pollForCompletion()` throws `WorkflowStuckException` when its polls run out

When no close event arrives within its polls, `pollForCompletion()` now throws
`Gplanchat\Durable\Exception\WorkflowStuckException`, built by the new
`WorkflowStuckException::pollsExhausted()`, with the same message as before. It used to throw a
plain `\RuntimeException`. Every host that waits through the Temporal client sees the new type.

**What to do:** nothing if you catch `\RuntimeException`: `WorkflowStuckException` extends it. To
tell a wait that ran out from a workflow that failed, catch `WorkflowStuckException` first; its
`executionId` property names the execution.

### Laravel: the clock and the Temporal client are bound by class (#879)

`DurableServiceProvider` now binds `Psr\Clock\ClockInterface` and
`Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface`. The runtime and
`gplanchat/durable-filament` read the clock through `ClockInterface`, which resolves
`durable.clock` each time it is asked. Every route #617 documents keeps working for both: a clock
bound under `durable.clock` with `instance()` or `singleton()`, before or after the provider
registers, reaches the runtime and the dashboard. Binding `ClockInterface` reaches both as well.

**What breaks.** If you bind `Psr\Clock\ClockInterface` before or after `DurableServiceProvider`
registers, Durable reads that clock, and `durable.clock` no longer reaches Durable. Until now,
Durable read `durable.clock` and ignored a `ClockInterface` binding.

**What to do**, only if your application binds `ClockInterface` and Durable must not read that
clock: bind `ClockInterface` as a delegate to `durable.clock`, without `singleton()`, so that it
follows a later rebinding of `durable.clock`:

```php
$this->app->bind(\Psr\Clock\ClockInterface::class, fn($app) => $app->make('durable.clock'));
```

`durable.clock` stays `SystemClock` unless you bind another clock under it, before or after the
provider registers. Your application's other PSR-20 consumers then read that same clock: Durable
and your application can no longer read two different clocks.

The Temporal client is bound under its interface, and `durable.temporal.client` is now an alias of
that binding. That id was never documented: an `instance('durable.temporal.client', …)` done after
the provider registers no longer reaches `TemporalRuntimeAssembly`. Bind
`WorkflowServiceClientInterface` instead.

### A child memo key `durableExecutionId` or `durableWaitingOn` fails on every backend (#889)

Durable reserves both keys: on Temporal it writes them in a child's memo itself. The
`ChildWorkflowOptions` constructor now throws `UnsupportedByBackendException` when `$memo`
contains either key, on every backend. Before, only the Temporal bridge refused them, and the SQL
and in-memory backends recorded them. The check runs when the options are built, so a run in
flight that rebuilds such options during replay fails too.

**What to do:** rename the memo key before you deploy this version. Replay compares a child's type
and input with the journal, not its memo, so a run in flight resumes with the new key. A run that
replays on code still using one of the two keys fails at `new ChildWorkflowOptions()`.

### In-memory runner: a continue-as-new chain stops after 10 continuations (#888)

`InMemoryWorkflowRunner` follows a continue-as-new chain to its last execution. Past
`maxContinuations` continuations (default `InMemoryWorkflowRunner::DEFAULT_MAX_CONTINUATIONS`, 10),
it throws the new `Gplanchat\Durable\Exception\ContinuationCapReachedException`, naming the
execution the caller started and the cap. It extends `WorkflowStuckException`, so a `catch` on
`WorkflowStuckException` catches it. `WorkflowStuckException` is no longer `final` and its
constructor is `protected`. `InMemoryWorkflowRunner`, `WorkflowTestEnvironment::inMemory()` and the
Magento `RuntimeFactory` gain an optional last argument `int $maxContinuations`; `0` allows no
continuation, and a negative value throws `\InvalidArgumentException`, on Magento when the
factory is built. A test whose chain
runs past 10 continuations passes `maxContinuations: <n>`; nothing else to migrate.

### durable-rector: the SDK migration marks the constructs it leaves as they are

A run of the `temporal-sdk.php` set now adds a `// durable-rector:` comment in three places where it
used to leave the code unchanged without a word:

- above every statement that references `ApplicationFailure`, `ServerFailure`, `TerminatedFailure`
  or `TimeoutFailure`: a `catch` (marked above its `try`), a `new`, a `throw`, an `instanceof`, a
  static call, a `::class`, a parameter or return type (marked above its method or function,
  #909). Durable has no counterpart for these four failures, and once `temporal/sdk` is removed the
  reference no longer resolves. The `use` import is not marked;
- above every `Temporal\Promise` call the rules do not rewrite: a method other than `all`, `any`
  and `some`, one of those three with no argument, and `some()` without a count;
- above an activity interface whose prefix the rule cannot turn into a Durable activity name (a
  computed prefix, a literal one that does not end in a dot, or `'.'` alone), and
  above an activity method whose `#[ActivityMethod(name:)]` is not a string literal. The contract
  keeps its SDK attributes, as before. A prefix with several segments, such as `'Billing.Order.'`,
  is converted: both engines give the same activity names (#907). The prefix `'.'` used to become
  `#[AsActivity(name: '')]`, which renamed `.charge` to `charge`; it is now marked.

**What to do:** nothing before the run. After it, search for `durable-rector:` and handle each
marker by hand; the README of `gplanchat/durable-rector` lists what the set still changes or skips
without a marker. A second run adds no second marker. A failure marker written by an earlier run
keeps its old text ("a catch on it never matches after migration"), and a re-run adds no second one.

### `DurableTestCase` passes `budgetSeconds` and `maxContinuations` to the runner (#897)

`DurableTestCase::createWorkflowTestEnvironment()` and `createWorkflowRunner()` gain two optional
last arguments, `float $budgetSeconds` and `int $maxContinuations`, with the runner's defaults
(`InMemoryWorkflowRunner::DEFAULT_BUDGET_SECONDS` and `DEFAULT_MAX_CONTINUATIONS`). Both go to
`WorkflowTestEnvironment::inMemory()` unchanged.

**What to do:** if a subclass of `DurableTestCase` overrides either method, add the two parameters
to its signature; without them, PHP fails to load the class. Otherwise nothing.

### New: PHPStan reports a stub that could be an `#[Activities]` parameter (#778)

`gplanchat/durable-phpstan` has a new rule, `durable.activityStubCouldBeParameter`. It reports an
`$env->activityStub()` call that the workflow method could receive as an `#[Activities]`
parameter, with no options or with literal `ActivityOptions::of()` values. The message gives the
attribute and the `@param ActivityStub<Contract>` docblock to write. Nothing is rewritten. The rule
stays silent when the move would change what runs: computed options, `default()`, an empty
`taskQueue`, or a stub that a signal, helper or closure reads. Code that already fails, such as
`of(0)` or a contract with no `#[AsActivityMethod]`, is reported with a warning: after the move,
the worker refuses to register the workflow. The extension's README lists every case, and the
shapes the rule does not see.

**Who is affected:** a project that runs PHPStan with the extension and builds activity stubs with
`activityStub()`. Its analysis can report new errors after the upgrade.

**What to do:** move the stub to the parameter the message gives, or keep it and ignore the rule
with `- identifier: durable.activityStubCouldBeParameter` under `ignoreErrors` in `phpstan.neon`.
To ignore it on one call only, add `// @phpstan-ignore durable.activityStubCouldBeParameter` on
that line.

### Laravel `memory` backend: a new run is queued until `durable:drain` runs it (#881)

On the `memory` backend, `WorkflowResumeDispatcher::dispatchNewWorkflowRun()` queues the run and
returns. It no longer drives the run inside the call. The new command `durable:drain` drives what
the process has queued, within the ten-second budget. The `memory` backend registers it; the other
backends do not. `InProcessWorkflowResumeDispatcher::drain()`, which the command calls, is now
public. With the run queued, a continue-as-new marks the old run completed before its next run
runs.

**Who is affected:** an application or a test on Laravel's `memory` backend that starts a run with
`dispatchNewWorkflowRun()` and expects it to have run when the call returns.

**What to do:** after `dispatchNewWorkflowRun()`, drain in the same process:

```php
$dispatcher->dispatchNewWorkflowRun($executionId, 'greeting', $payload);
app(InProcessWorkflowResumeDispatcher::class)->drain();
```

The provider registers `durable:drain` in a console process only (tests, commands, queue
workers). There, `Artisan::call('durable:drain')` does the same; in an HTTP request the command
does not exist. The journal of the `memory` backend lives in the process, so a separate
`php artisan durable:drain` starts with an empty queue and drives nothing. Nothing changes on
`illuminate` and `temporal`.

### Temporal: `pollForCompletion()` throws the exception the journal backends raise (#872)

`WorkflowClient::pollForCompletion()` used to throw a plain `\RuntimeException` for every run that
did not complete. For a failed workflow it now throws:

- the workflow's own exception, built as `new $class($message, $code)`, when its class loads in the
  calling process and that constructor gives back the recorded message;
- `DurableWorkflowAlgorithmFailureException` for an activity failure the workflow did not catch,
  with the activity's exception as previous, as the in-memory and SQL backends do;
- `DurableNexusOperationFailedException` or `DeadlineExceededException` for an uncaught Nexus
  failure or deadline;
- the new `Gplanchat\Durable\Exception\WorkflowFailedException` in every other case: a class that
  does not load, a constructor that takes other arguments, or a failure without Durable details (a
  worker that is not Durable). Its message is the one the server recorded, prefixed with
  `Workflow "<execution id>" failed:` as before.

A cancelled run throws `WorkflowCancelledException` with its reason. A timed-out run throws the new
`WorkflowTimedOutException`, and a terminated run the new `WorkflowTerminatedException` with the
termination reason. To rebuild the activity's exception, the workflow worker adds a `cause` entry
to the failure details it writes. A run that failed before the upgrade has no `cause`: its
`DurableWorkflowAlgorithmFailureException` carries a `WorkflowFailedException` as previous.

**Who is affected:** code around `pollForCompletion()` that catches `\RuntimeException`, directly or
through a host that waits with it: the Symfony bench runner, Laravel's `WorkflowClientInterface`,
the Nexus demo commands and `MagentoRuntime::run()` with a DSN. The new `WorkflowFailedException`,
`WorkflowTimedOutException` and `WorkflowTerminatedException` extend `\RuntimeException`, as do
`WorkflowCancelledException`, `DurableWorkflowAlgorithmFailureException` and
`DeadlineExceededException`. A workflow exception that does not, such as
a `\LogicException`, a plain `\Exception` or `DurableNexusOperationFailedException`, now reaches the
caller as its own class, and `catch (\RuntimeException)` no longer catches it.

**What to do:** catch the workflow's exception class, as on the journal backends, and widen the
remaining catch to `\Throwable`:

```php
try {
    $result = $client->pollForCompletion($executionId);
} catch (OrderRejected $e) {
    // the workflow's own exception
} catch (\Throwable $e) {
    // was: catch (\RuntimeException $e)
}
```

## 0.1.0-beta1

### A failed retry enqueue is sent again; journals gain `ActivityRetryQueued` (#590)

**Who is affected**: code that reads journal events by type (a custom mapper, a `match` without a
default, a projection), and code that builds `ActivityMessageProcessor` itself.

- When an attempt fails and will retry, the worker now appends
  `Gplanchat\Durable\Event\ActivityRetryQueued` once the transport took the next attempt: the
  counterpart of Temporal's dispatch task. A redelivered failure without it queues the retry again;
  before, the retry was lost when the broker refused it. Handle or skip the new type where events
  are read by type. Journals recorded before have none, and read as before.
- `ActivityMessageProcessor` takes an eighth, optional argument,
  `Gplanchat\Durable\Port\ActivityAttemptClaimInterface`: one worker per activity attempt. It
  defaults to `NoActivityAttemptClaim`, right for one process. The Symfony bundle wires
  `LockActivityAttemptClaim` on a DBAL journal (the resume lock's factory and TTL) and the Laravel
  provider wires `ActivityAttemptLock` (the resume lock's cache store and TTL). A host that builds the
  processor for several workers passes its own shared-lock implementation. A copy whose attempt
  another worker holds throws `Gplanchat\Durable\Exception\ActivityAttemptDeferred`: the bundle
  turns it into a recoverable Messenger failure, retried on the transport's retry strategy whatever
  `max_retries` says, and `RunActivityJob` queues the
  same attempt again 10 s out. A host that calls `process()` itself catches it and redelivers later.

No Rector rule: nothing is renamed, and the new argument is optional.

### Temporal: the journal workflow's leftovers are gone (#594)

**Who is affected**: code that used `Gplanchat\Bridge\Temporal\Journal\JournalStateResolver`,
`TemporalConnection::journalWorkflowId()`, `TemporalConnection::$workflowType`,
`TemporalConnection::$signalAppend`, `TemporalConnection::DEFAULT_WORKFLOW_TYPE` or
`TemporalConnection::DEFAULT_SIGNAL_APPEND`, and every DSN that sets `workflow_type`.

**Why.** They served the `DurableJournal` workflow, which recorded Durable's events as signals.
Nothing has started or read that workflow since #356: each run is a Temporal workflow of its own
type, and Temporal's history is the journal.

**What to do.**

- Remove `workflow_type` from `durable.temporal.dsn` (Symfony), from `temporal.dsn` in
  `config/durable.php` (Laravel) and from `durable/temporal/dsn` in `env.php` (Magento). A DSN that
  still sets it is refused with a message that names this entry.
- Code that builds `TemporalConnection` directly: drop the `workflowType:` and `signalAppend:`
  arguments. They were the fourth and fifth parameters, so a call that
  passes the following arguments by position now shifts: pass them by name.
- No Rector rule: the DSN key lives in configuration, which Rector does not read, and the hosts
  build `TemporalConnection` from the DSN, so a hand-written constructor call is rare.
- Nothing replaces `JournalStateResolver` or `journalWorkflowId()`. A `durable-journal-*` workflow
  left on a cluster from before #356 can be read with the Temporal CLI or UI.

### Magento reads the cluster's history through; `TemporalJournalEventStore` is gone (#356, #372)

**Who is affected**: a Magento store with `durable/temporal/dsn` set, and code that built
`Gplanchat\Bridge\Temporal\TemporalJournalEventStore` itself.

- With a DSN, `RuntimeFactory::create()` now reads through
  `Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore`, as the Symfony and Laravel
  hosts do. A run executed in the process (`MagentoRuntime::run()`) keeps its events in memory,
  as without a DSN; it no longer signals a `durable-journal-<id>` workflow on the cluster. Start
  what must survive a process with `RuntimeFactory::workflowClient()->startAsync()`.
- The `durable-journal-*` workflows the old store left on the cluster never complete, and nothing
  reads them any more: terminate them once no in-process run is under way, for instance
  `temporal workflow terminate --query 'WorkflowId STARTS_WITH "durable-journal-"' --reason "Durable #356"`.
- `Gplanchat\Bridge\Temporal\Journal\HistoryPageMerger`, which only that store used, is gone
  too: read a history page by page with `Grpc\TemporalHistoryCursor::events()`.
- Code that built the store: take
  `(new TemporalRuntimeAssembly($client, $connection, $registry, $loader))->readThroughEventStore(new InMemoryEventStore())`.
  No Rector rule: the constructor takes different arguments, and the write side does not exist
  any more.

### `NullEventStore` is gone; a backend refuses by name what it cannot honour

**Who is affected**: an application that built a `Gplanchat\Durable\Store\NullEventStore` itself,
or that implements `WorkflowCommandBufferInterface`. An application that only runs workflows has
nothing to change.

**What changed** (DUR051):
- `NullEventStore` answered an empty history, which is what a run that has not started looks like.
  A workflow given it would run its activities again instead of failing. It is replaced by
  `NoLocalJournalEventStore`, which refuses every call with
  `Gplanchat\Durable\Exception\UnsupportedByBackendException`.
- On Temporal, the command buffer's `completeChildWorkflow()` and `failChildWorkflow()` now refuse
  in the same way, instead of doing nothing. No path of the component reaches them there.

**What to write.** There is no Rector rule for this, on purpose: renaming the class would turn a
sink into a store that throws on its first write, and only you know which of the two you meant.
- If you wanted a store that keeps nothing between processes, use `InMemoryEventStore`.
- If you wanted the guarantee that nothing is ever read or written locally, use
  `new NoLocalJournalEventStore('<your backend>')`.

A command buffer of your own should refuse what its backend cannot honour, by throwing
`UnsupportedByBackendException::forMethod($backend, __FUNCTION__, $whatToUseInstead)`, rather
than accepting the call and dropping it.

### Temporal: two execution ids no longer share a workflow id

**Who is affected**: applications on the Temporal backend whose execution ids contain a character
outside `[a-zA-Z0-9._-]` (a `/`, a space, a colon…) or are longer than 900 characters. UUIDs, ULIDs
and other ids made of letters, digits, `.`, `_` and `-` keep their workflow id, and nothing changes
for them.

**Why.** The workflow id replaced every other character with `-` and cut at 900, so `order/42`,
`order 42` and `order-42` all became `durable-order-42` (#566). The second start was refused, and
a signal or an update could reach another execution's run.

**What changes.** Such an id now starts under `durable-<sanitised prefix>~<sha256 of the id>`, one
workflow id per execution id. `WorkflowClient::workflowIdOf()` gives it, and
`WorkflowClient::workflowId()` gives the one to address a run by.

**Runs already in flight.** Until 0.1.0-beta1, addressing an execution (signal, update, query,
history, `findRun()`) that has no run under its new workflow id falls back to its old one, but only
when the run found there was started with that very execution id. Nothing to do if those runs end
before you upgrade to 0.1.0-beta1. After that the fallback is gone: drain them first, or restart
them under their new workflow id.

### On Temporal, the dashboards link a run by its execution id, not the server's run id

**Who is affected**: an application on the Temporal backend with links to its run pages saved
before this version. That covers the Magento grid's run links, the Sylius plugin's
`/dashboard?run=…` and the Symfony bench's `?run=…`. Those links carried the server's run UUID.
They now answer "not found". The SQL and in-memory backends are not affected: there the two ids
are the same.

**Why.** A run page finds its run by the id the application started it with (#514). That is the
id a log line, an exception or `durable:execution:diagnose` names. There is deliberately no
fallback on the UUID: it would bring back a visibility query built from a URL.

**What to do.** Link with the execution id. If you only have the old link, open the run in
Temporal's UI by its UUID. Its workflow id there is `durable-` followed by the execution id,
with characters other than letters, digits, `.`, `_` and `-` replaced by `-`. The run's page in
the dashboard shows the execution id, and the server's run id beside it.

### Temporal: search attributes, opt-in, registered first

**Who is affected**: applications on the Temporal backend that want the run list to filter by
workflow name or execution id (#558, #557). Nothing changes until you turn the option on, and
nothing changes on the in-memory, DBAL and Illuminate backends.

**Why.** With `durable.temporal.search_attributes: true`, Durable writes `DurableWorkflowName` and
`DurableExecutionId` on every run it starts, including child workflows, continue-as-new runs and
Nexus-started workflows. A Temporal server refuses a start that sets an attribute the namespace has
no mapping for, so the option is off by default.

**What to do, before turning it on**, once per namespace:

```bash
temporal operator search-attribute create --namespace <ns> \
    --name DurableWorkflowName --type Keyword \
    --name DurableExecutionId --type Keyword
```

Then wait until the namespace can use them. This takes a few seconds, and the
[backends page](https://durable.rocks/docs/backends/#register-durables-search-attributes) has a
command that waits for it. On Temporal Cloud, add the two attributes in the Cloud UI or with
`tcld`. Only then set the option: `search_attributes: true` under `durable.temporal` on Symfony,
under `temporal` in `config/durable.php` on Laravel, and `durable/temporal/search_attributes` in
`env.php` on Magento. Turned on against a namespace without the mapping, every start fails with
`Namespace <ns> has no mapping defined for search attribute DurableExecutionId`.

Runs started before the option was on don't carry the attributes. The unfiltered run list still
shows them, but a filtered one doesn't. A value longer than the 255 characters Temporal documents
keeps at most its first 189 bytes and ends with a hash of the whole: an exact filter still finds it, a
prefix filter only within those bytes.

### `WorkflowRunCatalogInterface::listRuns()` takes a filter, and the port gains `canFilterRuns()`

**Who is affected**: only whoever **implements** `WorkflowRunCatalogInterface`. Code that calls
`listRuns()` keeps working, since the new parameter is optional. Code that passes a filter checks
`canFilterRuns()` first, and offers no filter when it is `false`. Rector cannot write the filter for
you, because only the implementer knows how the runs are stored.

**Why.** The run list filters by workflow name and by execution-id prefix (#558, #557), without
paging until a run shows up.

**What to write.**

- Add `?WorkflowRunFilter $filter = null` as the fourth parameter of `listRuns()`, and honour both of
  its fields. Compare the whole `workflowName` and the start of the execution id with
  `executionIdPrefix`. Take every character literally (`%` and `_` included, so not a bare SQL
  `LIKE`) and keep case: MySQL's default collations fold it, so compare as bytes there. A `null`
  field is no filter, and the value object already turns an empty string into `null`.
- Add `canFilterRuns(): bool`, and return `true` when `listRuns()` honours the filter. When it
  cannot, return `false` and throw `RunFilterUnavailableException` for a non-empty filter. Never
  ignore the filter, and never answer an empty page in place of the refusal.
- A filtered page may come back shorter than `$limit`, even empty, with a `nextCursor`: only a
  `null` cursor means the end.

```php
use Gplanchat\Durable\Exception\RunFilterUnavailableException;
use Gplanchat\Durable\Observation\WorkflowRunFilter;

public function canFilterRuns(): bool
{
    return true;
}

public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20, ?WorkflowRunFilter $filter = null): WorkflowRunPage
{
    // … narrow by $filter?->workflowName and $filter?->executionIdPrefix as well as by $status
}
```

`WorkflowRunCatalogConformanceTestCase` expects a catalog to filter. If yours cannot, override
`expectsToFilterRuns()` to return `false`: the suite checks that `canFilterRuns()` agrees with it.
It also follows `canFilterRuns()`. When it is `true`, the suite checks
both filters, their case and the literal characters. When it is `false`, it checks that a filter is
refused and that an empty one is not.

### `WorkflowRunCatalogInterface` gains `findRun()`

**Who is affected**: only whoever **implements** `WorkflowRunCatalogInterface`, that is, whoever
writes a run catalog. Pages that read the catalog have nothing to change. Rector cannot write the
method for you, because only the implementer knows where the run is stored.

**Why.** A run page links to a run by its id alone (#264). Before this method, the only way to get
a `WorkflowRunDescription` was `listRuns()`, so the page had to go through the list page by page
until the id appeared, and could not reach an old run.

**What to write.** Return the run whose `executionId` is the one given, described exactly as
`listRuns()` would describe it, or `null` when the catalog does not know it. On a backend that
chains runs under one execution, return the current run of the chain:

```php
public function findRun(string $executionId): ?WorkflowRunDescription
{
    $row = $this->rows->find($executionId);

    return null === $row ? null : $this->describe($row); // the mapping listRuns() uses
}
```

`WorkflowRunCatalogConformanceTestCase` checks that every listed run is found again with the same
facts, and that an unknown id gives `null`. Name the parameter `$executionId`, as the port does: a
caller passing it by name (`findRun(executionId: …)`) fails on a catalog that names it otherwise.

### The bundle's services live under `durable.*` ids; their class ids are aliases

**Who is affected**: an application compiler pass that calls `getDefinition()` or `hasDefinition()`
on one of the bundle's class or interface ids: `ExecutionEngine`, `ExecutionRuntime`,
`WorkflowRegistry`, `ActivityExecutor`, `WorkflowResumeDispatcher`, the Durable handlers and
commands, `DurableDataCollector`, the Temporal client, RPCs and task runner, and the others listed
in #342. Those ids are now aliases of `durable.*` definitions, with the visibility they had, so
autowiring, `->get()` and `decorates:` are unchanged. Most of them then became private, see "Only the
documented services stay public" below. In the pass:

```php
$container->getDefinition(ExecutionEngine::class); // throws: the id is an alias
$container->findDefinition(ExecutionEngine::class); // follows the alias to durable.engine

$container->hasDefinition(SetupCommand::class);    // always false now
$container->has(SetupCommand::class);              // true when the command is registered
```

### DBAL without a Temporal DSN no longer registers `durable.event_store.inner`

**Who is affected**: an application on the `dbal` backend, with no `temporal.dsn`, that decorates the
private `durable.event_store.inner`. That in-memory store had no reader there, and it is no longer
registered, so the decoration fails to compile. Decorate `EventStoreInterface` instead (#342).

### A worker refuses to reset an in-memory Durable transport

**Who is affected**: anyone running `durable:worker` or `messenger:consume` on a Durable transport
configured as `in-memory://` without `--no-reset`, which is the guide's `when@test` profile (#444).
That combination used to hang: the worker resets services after each message, which empties an
in-memory transport, so the activity a workflow queued was lost and the run stayed on
`ActivityScheduled`. The worker now refuses to start and names the way out: add `--no-reset` in the
process that dispatched, drain the run in the test (`DurableBundleTestTrait`), or use real
transports. Real transports and non-Durable transports are not affected.

### Laravel and Symfony on Temporal: decorating an intermediate service no longer reaches the services built from it

**Who is affected**: an application on the Temporal backend that decorates or replaces one of the
Temporal services **in order to change the services built from it**: `TemporalHistoryCursor`,
`WorkflowServiceExecutionRpc`, `WorkflowServiceNexusRpc`, `WorkflowTaskRunner` or
`WorkflowClientInterface`. On Laravel, through `$app->extend()` or a later `singleton()`; on
Symfony, through a service decorator or a compiler pass that rewrites their definitions.

These services now come from one `Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly` (#356). Every
id still resolves, to the assembly's object. But the run catalog, the task processor and
the read-through store are built inside the assembly, not resolved through the container, so a
decorated cursor or RPC is no longer the one they use. The heartbeat sender is the exception: the
activity worker still takes whatever `ActivityHeartbeatSenderInterface` resolves to.

Rector cannot help: this is container wiring. To migrate:

1. To change what a service does, decorate **that** service: `$app->extend(WorkflowRunCatalogInterface::class, …)`
   on Laravel, or `decorates: durable.run_catalog.temporal` on Symfony, still wraps what the
   application resolves.
2. To change what several services share (the cursor, the client), provide an assembly of your
   own, built with your client or connection: `$app->instance(TemporalRuntimeAssembly::class,
   $assembly)` on Laravel before the services resolve, or a definition for the
   `Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly` service on Symfony. Every Temporal
   service then comes from it.

Magento is unaffected: it never exposed these objects to its container.

### The run list says what a running run waits on: `waiting_on` on `durable_workflow_runs`

**Who is affected**: applications on the DBAL or Illuminate backend whose `durable_workflow_runs`
table was created before this version. Nothing breaks: without the column, workers run as before and
the dashboard does not say what a run waits on. Add the column to get that. No data to backfill: the
next suspension of each run writes it.

- **Laravel**: `php artisan migrate`. The package ships a migration that adds the column when it is
  missing.
- **Symfony with Doctrine Migrations**: `bin/console doctrine:migrations:diff` generates the
  `ADD waiting_on` statement. When the journal lives on another connection than the ORM, use the SQL
  below.
- **Anything else**, by hand:

```sql
ALTER TABLE durable_workflow_runs ADD waiting_on TEXT DEFAULT NULL;
```

Restart the workers after the change: they read the table's columns once per process.

A projection of your own keeps compiling. To report waits, also implement
`WorkflowRunWaitProjectionInterface::recordWait()` and fill `WorkflowRunDescription::$waitingOn`
while the run is running.

### Unused gRPC wrappers removed from the Temporal bridge

**Who is affected**: code that called one of these on `gplanchat/durable-bridge-temporal` directly.
Nothing in this repository, its demos or its documentation did (#372).

| Removed | If you used it |
|---|---|
| `Grpc\WorkflowServiceActivityRpc::updateActivityOptions()`, `pauseActivity()`, `unpauseActivity()`, `resetActivity()` | call the same RPC on `WorkflowServiceClientInterface` |
| `Grpc\WorkflowServiceActivityRpc::recordActivityTaskHeartbeatById()`, `respondActivityTaskCompletedById()`, `respondActivityTaskFailedById()`, `respondActivityTaskCanceledById()` | the task-token variants remain; or `WorkflowServiceClientInterface` |
| `Grpc\WorkflowServiceActivityRpc::startActivityExecution()`, `describeActivityExecution()`, `pollActivityExecution()`, `listActivityExecutions()`, `requestCancelActivityExecution()`, `terminateActivityExecution()`, `deleteActivityExecution()` | call the same RPC on `WorkflowServiceClientInterface` |
| `Grpc\WorkflowServiceExecutionRpc::pollWorkflowExecutionUpdate()` | `WorkflowServiceClientInterface::PollWorkflowExecutionUpdate()` |

Each wrapper only added the default gRPC deadline (`TemporalGrpcTimeouts::SHORT_US`); pass it as
the `timeout` call option when calling the client directly.

### The run pages mask payload secrets too

**Who is affected**: operators of the Sylius plugin's dashboard and of the Magento run page. Each
event's details are now masked the way the profiler panel and `durable:execution:diagnose` mask them
(#507, and the diagnose section of this file): values under keys such as `password`, `token` or
`api_key` show as masked, long strings are truncated, and the redactor is the one the application
registered for the profiler. Nothing to change in code. `RunTimeline::of()` takes an optional
`PayloadRedactorInterface` as its second argument and `RunDashboard` as its third. On Magento,
`Block\Adminhtml\ProcessDetail`'s constructor gains a `PayloadRedactorInterface $redactor` before
`$data`: a subclass that overrides the constructor passes it on.

### Unused helpers removed from the core

**Who is affected**: code that called one of these. Nothing in this repository, its demos or its
documentation did, and none has a replacement to migrate to: each one read state the engine keeps
for itself.

| Removed | If you used it |
|---|---|
| `Awaitable\ExecutionBoundAwaitable` (interface) | implement `Awaitable` directly |
| `ExecutionContext::pendingTimers()`, `pendingActivities()` | nothing: they exposed the engine's own bookkeeping |
| `Awaitable\QuorumAwaitable::required()` | keep the count you passed to `some()` |
| `Transport\InMemoryActivityTransport::pendingCount()`, `inspectPendingActivities()` | `peek()` and `nextDueAt()` remain |
| `Transport\ActivityMessage::withAttempt()` | `retryingIn($message->retryDelay)` for `withAttempt($message->attempt + 1)`; for any other number, `new ActivityMessage(…, attempt: $n, firstQueuedAt: $message->firstQueuedAt, retryDelay: $message->retryDelay)` (named arguments; all properties are public). |
| `Failure\ActivityRetryState::isTerminalBusinessFailure()` | `\in_array($state, [ActivityRetryState::NonRetryableFailure, ActivityRetryState::MaximumAttemptsReached, ActivityRetryState::Timeout, ActivityRetryState::RetryPolicyNotSet], true)` |
| `Query\WorkflowQueryRunner::signalsReceived()`, `updatesHandled()` and their `WorkflowQueryEvaluator` statics | read `WorkflowSignalReceived` / `WorkflowUpdateHandled` from the journal |

### `durable.backend` replaces four keys; the configuration refuses what it used to build

**Who is affected**: Symfony applications that set `event_store.type`, `workflow_metadata.type`,
`child_workflow.parent_link_store.type` or `temporal.journal`. They keep working for this version:
the backend is derived from them, and each one set reports a deprecation. Rector does not read
YAML; the translation is by hand.

| Before | After |
|--------|-------|
| nothing, or the three `type` keys at `in_memory` | `backend: in_memory`, or nothing |
| the three `type` keys at `dbal` | `backend: dbal` |
| `temporal.dsn` set (and `journal: true`) | `backend: temporal` with the same `temporal.dsn` |
| `event_store.type: dbal`, `temporal.dsn` and `journal: false` | `backend: dbal` with the same `temporal.dsn` |

Remove the four old keys once `backend` is set. Left in place, one that disagrees with `backend` is
an error, not a silent override.

Also refused from this version, when the container is built, with the configuration path in the
message: a `temporal.dsn` that is not a non-empty string (`null` still means no cluster), a
negative `max_activity_retries`, and an `activity_contracts.contracts` entry that is not an
interface the autoloader finds. The last one used to surface as a `ReflectionException` in
`cache:warmup`.

The mutual exclusion of a DBAL journal and a Temporal journal is now an
`InvalidConfigurationException` rather than a `LogicException`. Code that caught the latter around
a container build catches the former.

`activity_transport.table_name` is gone: nothing ever read it, and the same release removes it
(see "`durable.activity_transport.table_name` is removed" below). Delete the line.

`profiler.enabled` is new. It defaults to `%kernel.debug%`, which is what the bundle did before;
set it to keep the profiler out of a debug worker, or in a non-debug staging build.

### `durable:execution:diagnose` and the profiler panel mask payload secrets

**Who is affected**: scripts that read secrets out of `durable:execution:diagnose --json`, and
applications whose payloads carry values under keys matching
`/password|secret|token|authorization|card|api[_-]?key/i`. Those values now print as `***`, and strings over
1 KiB are truncated. Add `--raw` to get the payload as stored.

To change what is masked, implement `Gplanchat\Durable\Observation\PayloadRedactorInterface` and
alias the interface to your service; both surfaces use it.

The profiler reads at most 20 ids from `?durable_execution=`, and drops an id that is not printable
ASCII without spaces, quotes, ampersands or angle brackets.

### The Sylius plugin follows the Sylius 2 layout; its route follows the admin prefix

**Who is affected**: every Sylius shop that installs `gplanchat/durable-plugin`. The route import
moved from `Resources/config/` to `config/`. It is YAML, so Rector cannot rewrite it; change the
one line by hand:

```yaml
# config/routes/durable_plugin.yaml
gplanchat_durable_plugin:
    resource: '@DurablePlugin/config/routes.yaml'   # was '@DurablePlugin/Resources/config/routes.yaml'
```

Template names do not change (`@DurablePlugin/admin/dashboard/index.html.twig`): Symfony reads a
bundle's `templates/` under the same namespace as its `Resources/views/`.

The dashboard's path is now `/%sylius_admin.path_name%/durable/dashboard` instead of a hardcoded
`/admin/durable/dashboard`. A shop that keeps the default admin prefix sees no change; one that
sets `SYLIUS_ADMIN_ROUTING_PATH_NAME` now finds the page under its admin, behind its firewall.
The menu entry names its route, so the Sylius menu marks it active on the page. The package type
is `sylius-plugin`.

### The DBAL journal: `durable:setup`, no DDL inside a transaction, a new index on the run list

**Who is affected**: Symfony applications on the DBAL backend, and Laravel applications on the
Illuminate one.

- **A first write inside an open transaction no longer creates the tables.** On MySQL the
  `CREATE TABLE` committed that transaction implicitly, and the caller's commit then failed with
  "There is no active transaction"; every platform now refuses with `DurableSchemaMissing`, which
  names the fix. On Symfony, run `bin/console durable:setup` once per database (a deploy step,
  next to `messenger:setup-transports`), or let migrations create the tables. On Laravel, run
  `php artisan migrate`.
- **`durable_workflow_runs` gains an index on `(status, started_at)`.** `auto_setup` never alters an
  existing table. With Doctrine Migrations, `doctrine:migrations:diff` generates it; otherwise run
  `CREATE INDEX durable_workflow_runs_status_started_idx ON durable_workflow_runs (status, started_at);`.
  On Laravel, `php artisan migrate` adds it.
- **A `schema_filter` that rejects `durable_*` is honoured**: those tables are no longer declared to
  the Doctrine tooling, and the `CREATE TABLE` that came back in every diff is gone.

### New: `WorkflowDispatchObserverInterface`, the core port for dispatch observation

**Who is affected**: nobody has to change anything. `Gplanchat\Durable\Debug\WorkflowDispatchObserverInterface`
declares `onWorkflowDispatchRequested()`, which `DurableExecutionTrace` already had.
`TemporalWorkflowResumeDispatcher`'s fourth argument is now typed against it instead of
`DurableExecutionTrace`, so the Temporal bridge no longer imports the Symfony bundle (#345). Passing
a `DurableExecutionTrace` still works; a host without the bundle can now pass its own observer.

### `ResetDurableProfilerListener` is gone; the execution trace keeps its last 2 000 entries

**Who is affected**: code that referenced `Gplanchat\Durable\Bundle\EventListener\ResetDurableProfilerListener`,
to decorate or remove it. Delete the reference: `DurableExecutionTrace` now keeps its last 2 000
entries (`MAX_ENTRIES`) on its own, which holds on a Temporal worker too, where `kernel.reset`
never fires. Between two Messenger messages, `kernel.reset` still empties it.

### One type catches every Durable error: `Gplanchat\Durable\Exception\ExceptionInterface`

**Who is affected**: nobody has to change anything; this is an addition. Every error class under
`Gplanchat\Durable\Exception` and the two Nexus exceptions implement it, so a host can write
`catch (ExceptionInterface $e)` instead of listing them.

The control-flow signals — `WorkflowSuspendedException`, `ContinueAsNewRequested`,
`ChildWorkflowStartDeferred` — do not: they end a pass of workflow code on purpose, and a catch in
that code must let them through.

### The run list tells a run waiting for a worker: `picked_up_at` on `durable_workflow_runs`

**Who is affected**: applications on the DBAL or Illuminate backend whose `durable_workflow_runs`
table was created before this version. Nothing breaks: without the column, workers run as before and
the dashboard does not show which runs wait for a worker. Add the column to get that.

The rows already there are marked as picked up, since a run that predates the column cannot say, and
a false "waiting for a worker" on each of them would be worse than no signal.

- **Laravel**: `php artisan migrate`. The package ships a migration that adds the column when it is
  missing and marks the existing rows.
- **Symfony with Doctrine Migrations** (the bundle declares Durable's tables to the schema tool):
  `bin/console doctrine:migrations:diff` generates the `ADD picked_up_at` statement; add the
  `UPDATE` below to the generated migration before running it. When the journal lives on another
  connection than the ORM, the tool does not see its tables: use the SQL below.
- **Anything else**, by hand:

```sql
ALTER TABLE durable_workflow_runs ADD picked_up_at DATETIME DEFAULT NULL;  -- TIMESTAMP(0) WITHOUT TIME ZONE on PostgreSQL
UPDATE durable_workflow_runs SET picked_up_at = started_at WHERE picked_up_at IS NULL;
```

Workers read the table's columns once per process: restart them after the change, or they keep
running without recording pickups, and the runs they execute read as waiting for a worker.

A projection of your own (`WorkflowRunProjectionInterface`) keeps compiling. To report pickups, also
implement `WorkflowRunPickupProjectionInterface::recordPickup()`, and set `tellsWaitingForWorker` on
the `WorkflowRunPage` your catalog returns.

### The gRPC client composes a transport

**Who is affected**: code that builds a Temporal client by hand instead of through
`WorkflowServiceClientFactory::create()`. Every host (Symfony, Laravel, Magento) goes through the
factory and is unaffected.

`Gplanchat\Bridge\Temporal\Grpc\GrpcWorkflowServiceClient` now takes a
`Gplanchat\Bridge\Temporal\Grpc\GrpcTransport` — how one unary call travels — instead of the
generated stub, and `Gplanchat\Bridge\Temporal\Http\CurlGrpcWorkflowServiceClient` becomes that
transport over curl, `CurlGrpcTransport`.

| `v0.1.0-alpha12` | Now |
|---|---|
| `new GrpcWorkflowServiceClient(WorkflowServiceClientFactory::createStub($connection))` | `new GrpcWorkflowServiceClient(new ExtGrpcTransport($connection))` |
| `new CurlGrpcWorkflowServiceClient($connection)` | `new GrpcWorkflowServiceClient(new CurlGrpcTransport($connection))` |

Or ask the factory, which picks the transport from the DSN: `WorkflowServiceClientFactory::create($connection)`,
or `createTransport($connection)` for the transport alone. Rector cannot help: a class became an
argument of another, which no rename expresses.

### The Temporal workers are the bundle's, and `purpose=` is gone

**Who is affected**: a Symfony application on the Temporal backend (`durable.temporal.dsn` set).
Rector cannot help: the change is in YAML and in the commands that start the workers.

The `temporal://` Messenger transports that `messenger.yaml` used to declare three times over the
same DSN, told apart by `options.purpose`, no longer exist. The Durable bundle builds the workers
from `durable.temporal.dsn` and `messenger:consume` finds them by name:

| Before                                                   | Now                                                  |
|----------------------------------------------------------|------------------------------------------------------|
| `durable_temporal_journal` (`purpose` unset)             | `durable_workflows`                                  |
| `durable_temporal_activity` (`purpose: activity_worker`) | `durable_activities`                                 |
| `durable_temporal_nexus` (`purpose: nexus_worker`)       | `durable_nexus`, only if a Nexus handler is declared |
| `temporal://…?inner=…`, `purpose: application`           | removed — declare the inner transport directly       |
| `temporal-journal://`, `temporal-application://`         | refused, see the next section                        |

1. Keep the DSN in `durable.temporal.dsn`, once.
2. Under Temporal, remove from `framework.messenger.transports` every `durable_temporal_*`
   transport, **and** `durable_workflows` / `durable_activities`, with the routing that points at
   them. The container refuses to compile otherwise and names the transport to remove. With
   `durable.temporal.journal: false`, workflows run locally: keep your `durable_workflows` /
   `durable_activities` transports, only the Nexus one goes.
3. Rename the workers in supervisor, systemd, `.symfony.local.yaml` or wherever they start:
   ```bash
   bin/console messenger:consume durable_workflows
   bin/console messenger:consume durable_activities
   bin/console messenger:consume durable_nexus   # only if this application serves Nexus operations
   ```
4. Remove `Gplanchat\Bridge\Temporal\TemporalBridgeBundle` from `config/bundles.php`. It registers
   nothing any more and is deprecated; a kernel that still lists it keeps booting.

Laravel and Magento are unaffected: their workers never went through Messenger.

### The Temporal DSN names the server only: no `inner=`, no `temporal-journal://`

**Who is affected**: an application whose Temporal DSN — `durable.temporal.dsn`, the Laravel or
Magento host configuration, or the environment variable behind them — still starts with
`temporal-journal://` or `temporal-application://`, or carries `inner=`. Rector cannot help: the
value is a string in configuration.

Both encoded which worker a transport ran, which the previous section moved into the bundle.
`TemporalConnection::fromDsn()` now refuses the two schemes and names `temporal://` as the
replacement; `inner=` is no longer read, and `TemporalConnection::$innerMessengerDsn` is gone.

1. Replace `temporal-journal://` and `temporal-application://` with `temporal://` (or
   `temporal+tls://` for TLS).
2. Drop `inner=` from the DSN. The Messenger transport it pointed at, if you still need it, is
   declared directly in `framework.messenger.transports`.

### An unknown key in the Temporal DSN is refused

**Who is affected**: a Temporal DSN carrying a query key `TemporalConnection::fromDsn()` does not
read: a typo (`namesapce=`), a key from another client (`ssl=`), or a leftover `inner=`. It used to
be ignored, so `namesapce=orders` ran against the `default` namespace without a word. It now
throws an `InvalidArgumentException` naming the key and the accepted ones. Rector cannot help: the
value is a string in configuration.

1. Read the message: it names the key.
2. Fix the typo, or drop the key. For TLS, `temporal+tls://` or `tls=1`.

### Stubs refuse what PHP refuses

**Who is affected**: any application that calls an activity, Nexus operation or child workflow
contract through a stub. Nothing to write; calls that used to go through in silence now throw, and
that is the point.

The three stubs turn the arguments received by `__call` into a named payload. Three call mistakes
used to vanish there without a word, and travelled all the way into the journal — where they replay
identically, pass after pass, far from the offending call:

| The call                                        | Before                  | Now                      | What PHP does on an ordinary call                     |
|-------------------------------------------------|-------------------------|--------------------------|-------------------------------------------------------|
| unknown named argument                          | ignored                 | `BadMethodCallException` | `Error: Unknown named parameter`                      |
| required parameter not supplied                 | comes out `null`        | `BadMethodCallException` | `ArgumentCountError`                                  |
| parameter supplied positionally **and** by name | the positional one wins | `BadMethodCallException` | `Error: Named parameter overwrites previous argument` |

The type is `\BadMethodCallException` and not PHP's own because the call goes through `__call`: it
is the exception the SPL reserves for a method called wrongly, and it stays catchable.

**If one of these exceptions shows up in production**, it points at a call that was already wrong: a
child workflow started with a missing parameter went off with `null` and waited for a message that
never came. Rector can do nothing here — the fix is in your calling code, not in a mechanical shape.

These exceptions are deterministic: replayed identically on every redelivery, they burn through
Messenger's attempts down to the failure transport. Configure one.

### Eleven internal bundle services become private

**Who is affected**: an application that pulls one of these eleven ids out of the container with
`$container->get()`. Not one that receives them by autowiring, nor one that goes through their
interface.

The concrete implementations behind an alias and the projection decorators have no business being
container entry points: a public service escapes *inlining* and the removal of unused definitions,
and becomes a compatibility promise nobody meant to make.

| Now private                                                                                                                 | Ask for this instead                                 |
| --------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| `durable.event_store.dbal`, `durable.event_store.temporal`, `durable.event_store.inner`, `durable.event_store.*.projecting` | `Gplanchat\Durable\Store\EventStoreInterface`        |
| `durable.workflow_metadata_store.inner`, `durable.workflow_metadata_store.*.projecting`                                     | `Gplanchat\Durable\Store\WorkflowMetadataStore`      |
| `durable.run_catalog.dbal`, `durable.run_catalog.in_memory`, `durable.run_catalog.temporal`                                 | `Gplanchat\Durable\Port\WorkflowRunCatalogInterface` |

The three interfaces stay **public** and autowirable, and they point at the same instance: what
changes is the path to get there, not what you get. The rest of the bundle's surface goes private
too, see "Only the documented services stay public" below.

Rector can do nothing: rewriting a `$container->get('durable.event_store.dbal')` into an injection
requires knowing where the object is used, which no rule can guess. The table above is the
procedure.

### `WorkflowClientInterface::signal()` and `update()` take the call's id

**Who is affected**: only whoever **implements** `WorkflowClientInterface`, typically a test
double. Callers have nothing to change: the new parameters are optional.

**What was broken.** On Temporal native, a `DeliverWorkflowSignalMessage` or
`DeliverWorkflowUpdateMessage` sent through Messenger never reached the cluster (#333). Now it
does, and a Messenger retry after a lost answer must not deliver it twice. The message draws its
id once, when it is built; the client puts it on the wire as `request_id` or `update_id`, and the
cluster drops the duplicate.

Messages queued before the upgrade are delivered at-least-once: they carry no id, so each
redelivery draws a new one. Drain the queue before deploying for exactly-once.

**What to write.** Rector does it: `durable-upgrade.php` adds the two parameters to every
implementation. By hand, they are:

```php
public function signal(string $workflowId, \BackedEnum|string $signalName, array $args = [], ?string $requestId = null): void;
public function update(string $workflowId, string $updateName, array $args = [], ?string $updateId = null): mixed;
```

### `WorkflowHistorySourceInterface` gains `cancellationDelivery()`

**Who is affected**: only whoever **implements** `WorkflowHistorySourceInterface`, that is, whoever
writes a backend. Workflow code has nothing to change. Journals written before this version stay
readable: a delivery traced only by an operation cancelled with the `workflow_cancelled` reason
still counts as delivered.

**What was broken.** A cancellation delivered while the workflow waited on a condition left no
trace in the journal, because no operation was withdrawn. The next pass delivered it again at the
first await it suspended on. If a signal satisfying the condition had been recorded in between,
the replay walked past the condition and scheduled other code in the slot the compensation had
taken: a divergence (#317).

**What to write.** Return where the delivery was recorded, as a position comparable with the
ones `messageAt()` returns, and the operations it withdrew. On a journal that is walked, the
delivery is the new `WorkflowCancellationDelivered` event:

```php
public function cancellationDelivery(): ?array
{
    $position = 0;
    foreach ($this->eventStore->readStream($this->executionId) as $event) {
        if ($event instanceof WorkflowCancellationDelivered) {
            return ['position' => $position, 'targets' => $event->targets()];
        }
        ++$position;
    }

    return null;
}
```

A backend that stores events through its own mapper must also map `WorkflowCancellationDelivered`;
`EventStoreConformanceTestCase` now round-trips it.

### An inline child's failure no longer carries `getPrevious()` on the first pass

**Who is affected**: workflow code that catches `DurableChildWorkflowFailedException` from an
inline child (in-memory, DBAL and Illuminate backends) and reads `getPrevious()`. Temporal is
unchanged.

**What was broken.** The first pass handed the child's throwable as `getPrevious()` and left
`workflowFailureKind()` and `workflowFailureClass()` empty. Every replay did the opposite: no
previous, and a kind and class that were never recorded either. Code branching on them took one
path on the first pass and another on replay (#318).

**What to write.** Branch on the recorded information, which both passes now carry. Rector can do
nothing here: the replacement depends on what the workflow was checking.

```php
// Before: true on the first pass only
if ($e->getPrevious() instanceof PaymentRefused) { /* ... */ }

// After: the same answer on every pass
if (PaymentRefused::class === $e->workflowFailureClass()) { /* ... */ }
```

`workflowFailureContext()` carries the context the child's failure recorded.

### `WorkflowHistorySourceInterface` gains `hasSideEffectForSlot()`

**Who is affected**: only whoever **implements** `WorkflowHistorySourceInterface` — that is, whoever
writes a backend. An application that calls `sideEffect()` has nothing to change; it gets the fix for
free.

**What was broken.** `findSideEffectForSlot()` returns `mixed` and signalled "nothing recorded" with
`null`. A closure that legitimately returns `null` was therefore indistinguishable from an empty
slot: it was **re-executed on every replay pass**, and the journal grew by one `SideEffectRecorded`
per pass. That is the very guarantee `sideEffect()` exists to offer. The values `false`, `0`, `''`
and `[]` were not affected — the comparison was a strict `!==`.

**What to write.** A method that answers *does the slot exist*, without looking at what it carries.
Rector can do nothing here: the answer depends on how your backend stores its slots, and having it
guess one would produce an adapter that compiles and lies. The two shipped implementations show the
two expected shapes.

On a journal that is walked:

```php
public function hasSideEffectForSlot(int $slot): bool
{
    $index = 0;
    foreach ($this->eventStore->readStream($this->executionId) as $event) {
        if ($event instanceof SideEffectRecorded) {
            if ($index === $slot) {
                return true;
            }
            ++$index;
        }
    }

    return false;
}
```

On an array indexed by slot — and it is `array_key_exists()`, never `isset()`, which would reopen
exactly the hole this fix closes:

```php
public function hasSideEffectForSlot(int $slot): bool
{
    return \array_key_exists($slot, $this->sideEffects);
}
```

`findSideEffectForSlot()` keeps its signature and its behaviour: it returns the value, and returns
`null` both for an absent slot and for a slot carrying `null`. That is now written in its contract,
and `hasSideEffectForSlot()` is what decides whether the closure runs.


### `version()` honours `$minSupported` and `$maxSupported`

**Who is affected**: workflows that call `version()` with a `$minSupported` above the version some
of their executions are on. Typically, the `DEFAULT_VERSION` branch was deleted and `$minSupported`
raised, or `$minSupported` was never set to `DEFAULT_VERSION` in the first place.

**What was broken.** `$minSupported` was never read. An execution on a version whose branch had
been deleted silently took the branch that remained (#321). An execution whose recorded version was
above `$maxSupported`, after a rollback, carried on as well.

**What changes.** Outside the range, `version()` throws `WorkflowTaskFailure`, naming the change
point, the execution's version and the range. On Temporal the task fails and the execution waits
for code that supports it. On the journal backends the execution ends, as a replay divergence does.

**What to write.** Nothing, if every call keeps `ChangePoint::DEFAULT_VERSION` as its minimum for as
long as the old branch exists. Otherwise, set the minimum to the oldest version the code still
plays. Rector can do nothing here: only you know which branches the code still carries.

```php
// The DEFAULT_VERSION branch is still in the code: say so.
$version = $env->version('add-discount', minSupported: ChangePoint::DEFAULT_VERSION, maxSupported: 1);
```

### `version()` no longer switches an in-flight execution

**Who is affected**: every application that calls `version()`. Nothing to write; the behaviour
changes, for the better, and you should know how.

`version()` decides to return the old behaviour while the execution is still replaying. That signal
was deduced from the four slot kinds able to state their presence — activity, timer, child workflow,
Nexus operation — and left side effects aside, for the very reason the fix above just removed: their
presence could not be read without reading their value.

Consequence: an execution whose remaining work ahead consisted only of side effects was seen as
having reached the end of its history. It took the **new** branch in the middle of a replay and wrote
its version marker there — into a history written before the change point existed. With
`hasSideEffectForSlot()` now on the port, that case joins the others.

An execution that has already written a version marker keeps it: `versionForChangeId()` is consulted
first, and nothing in this change touches it.

### The profiler is no longer registered outside debug

**Who is affected**: an application that pulled `durable.execution_trace` out of the container in
production, or that injected `WorkflowExecutionObserverInterface` expecting the trace.

The collector, its trace, its reset listener and its Messenger middleware were registered under no
condition. The observer they install is injected into `ExecutionRuntime`, `ExecutionEngine` and
`ActivityMessageProcessor`: it therefore sat on the hot path of every execution in production, to
feed a page nobody serves there. And its trace was only emptied by a `kernel.request` listener, which
`messenger:consume` never triggers — a worker accumulated it for as long as it lived.

Outside `kernel.debug`, `WorkflowExecutionObserverInterface` now points at
`Gplanchat\Durable\Debug\NullWorkflowExecutionObserver`. The observation contract is intact; its
implementation is what no longer does anything. In debug, nothing changes, except that the trace
carries a `kernel.reset` tag and is therefore also emptied between two messages of a worker.

An application that wants to observe executions in production does not have to resurrect the
profiler: it implements `WorkflowExecutionObserverInterface` and aliases the interface to its own
service — what the profiler did, cheaper, and without accumulating a timeline for nobody's screen.

That alias also wins in debug, and that is a trade: the profiler panel then shows the Messenger
dispatches but no engine events (workflow runs, activities), and says nothing about why. Declare the
alias outside `when@dev` to keep them in development (#337).

### A successful activity writes `ActivityCompleted` only, no `ActivityTaskCompleted` (#262)

**Who is affected**: code that reads the journal and waits for `ActivityTaskCompleted` to learn
that an activity succeeded — a listener on the event store, a custom projection. On success the
worker used to append `ActivityTaskCompleted` and then `ActivityCompleted` with the same body; it now
appends `ActivityCompleted` alone. Read `ActivityCompleted`: it was already the event replay reads,
and it carries the same result. Failures do not change: one `ActivityTaskFailed` per attempt, then
`ActivityFailed`.

Journals recorded before keep both events. They replay and read as before: the class, its mapping
and the dashboard reader's handling of it stay. No Rector rule or script: what changes is which
event a listener receives at run time, not code Rector can rewrite.

### Dead code leaves the Temporal bridge (#372)

**Who is affected**: code that referenced one of these, none of which anything in Durable called:

- `Gplanchat\Bridge\Temporal\TemporalJournalGrpcPoller`: poll with `WorkflowServiceClientInterface::PollWorkflowTaskQueue()`.
- `Gplanchat\Bridge\Temporal\Journal\JournalWorkflowTaskProcessor`: `Worker\WorkflowTaskProcessor` runs workflow tasks.
- `JournalExecutionIdResolver::durableExecutionIdFromHistory()`: take the `WorkflowExecutionStarted` attributes from the history and call `durableExecutionIdFromStartedAttributes()`.

No Rector rule: there is no successor to rename to.

`Gplanchat\Bridge\Temporal\Profiler\TemporalEventConverter` moves to
`Gplanchat\Bridge\Temporal\Store\TemporalEventConverter`: the store and the command buffer use it,
the profiler never did. The `durable-upgrade` Rector set renames it.

`Gplanchat\Bridge\Temporal\Spike\NativeExecutionSpike` leaves the published package for the Symfony
bench (`App\Temporal\NativeExecutionSpike`, with its `durable:temporal:native-spike` command). It
was the DUR024 reference, not production code; `Worker\WorkflowTaskRunner` runs that path. Copy
the class from the bench if you ran it; no Rector rule, since the class is no longer installed.

### `DurableExecutionTrace::getTimelineForExecution()` is gone

**Who is affected**: code that called it on the `durable.execution_trace` service. Nothing in
Durable did. Filter `getTimeline()` by `executionId` instead:
`array_values(array_filter($trace->getTimeline(), fn(array $e): bool => ($e['executionId'] ?? '') === $id))`.

### `await()` takes an optional `label` for a condition

**Who is affected**: nobody has to change anything. `WorkflowEnvironment` is `final`, so the new
trailing parameter breaks no implementer. A condition awaited with `label: 'signal approve'` shows
`waiting on signal approve` in the run list instead of `waiting on condition at <file>:<line>`. The
label is display text: nothing records it while the run waits, it appears in the failure message of an
uncaught deadline, and replay never compares it. A label on a timer or an activity is refused with
an `InvalidArgumentException` (#324).

### Replay: on Temporal, a failed activity reports the attempt the server ran

**Who is affected**: workflows on the Temporal backend that catch a `DurableActivityFailedException`
and put `$e->attempt()`, or the exception's message (which names the attempt), into the payload of a
later activity, child workflow or Nexus operation. Everyone else has nothing to do. The journal
backends always reported the real attempt; Temporal said `1` (#547).

A run of that workflow that is in flight when you upgrade was recorded with `attempt 1` in that
payload. Replayed with the new reading, it schedules the real attempt, and the worker refuses the
task: `Replay divergence at activity slot N … history recorded "…attempt-1…", code scheduled
"…attempt-3…"`. Either drain those runs before deploying, or keep the old reading for the runs that
started before the change:

```php
} catch (DurableActivityFailedException $e) {
    $attempt = ChangePoint::DEFAULT_VERSION === $env->version('real-activity-attempt', minSupported: ChangePoint::DEFAULT_VERSION, maxSupported: 1)
        ? 1                  // started before the upgrade: the history recorded attempt 1
        : $e->attempt();

    return $env->await($activities->greet('attempt-' . $attempt));
}
```

### `WorkflowResumeDispatcher` gains `dispatchResumeAwaiting()`

**Who is affected**: only whoever **implements** `WorkflowResumeDispatcher`. The bundle's, the Laravel
provider's, the Temporal bridge's and the null dispatcher are updated. Code that calls the port is
not affected. Rector cannot write the method for you: only the implementer knows how its queue
delivers.

**Why.** Whoever journals a fact a workflow waits on (an activity's outcome, a child's outcome, a
signal, fired timers) now sends the resume before the append, and again after it (DUR050, #328;
DUR052, #584). The first send has to leave at once, carrying the `AwaitedFact` it announces; a
resume that arrives before that fact waits for it.

**What to write.** Send a `ResumeWorkflowMessage` carrying the fact, immediately, and nothing
where your transport runs the resume inline (a `sync` route): there it would always run before
the fact, and the resume sent after the append does the work.

```php
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;

public function dispatchResumeAwaiting(string $executionId, AwaitedFact $fact): void
{
    if (!$this->runsInline) {
        $this->send(new ResumeWorkflowMessage($executionId, [], $fact)); // not deferred
    }
}
```

A dispatcher whose backend owns delivery (as Temporal's does) implements it as a no-op.

### `durable.activity_transport.table_name` is removed

**Who is affected**: a Symfony application whose `durable.yaml` still sets it. Nothing ever read it.
It was first marked deprecated during this release's development, then removed before the release,
so no tagged version accepts it with a deprecation.

**Why.** It named an outbox that was never built, and DUR050 (#328) chose not to build one: the
resume is sent before the outcome and again after it.

**What to do.** Delete the line. Left in place, the container build fails with an
`InvalidConfigurationException` naming the unrecognized option.

### `WorkflowRunCatalogInterface::canFilterRuns()` takes the filter it is asked about

**Who is affected**: only whoever **implements** `WorkflowRunCatalogInterface`.

**Why.** A catalog may apply one filter and not another. Temporal Server before 1.23.0 rejects
`STARTS_WITH`, so on such a server the Temporal catalog takes a workflow name but not an
execution-id prefix (#523). It reads the server's version once, through `GetSystemInfo`, which
`WorkflowServiceClientInterface` now declares.

**What to write.** Add the optional parameter, and answer for the filter given: with none, whether
you can filter at all.

```php
public function canFilterRuns(?WorkflowRunFilter $filter = null): bool
{
    return true; // a catalog that applies every filter
}
```

`RunDashboard` asks about each part, applies the ones you accept, and tells the page which inputs
to offer. The conformance suite checks each filter case against your answer for that filter.

### `WorkflowHistorySourceInterface` returns value objects, not array shapes (#325)

**Who is affected**: code that implements `Gplanchat\Durable\Port\WorkflowHistorySourceInterface`
(a custom history source, a test double), and code that reads what it returns. The two history
sources Durable ships are converted.

| Method | Returned | Now returns |
|---|---|---|
| `findActivitySlotResult()`, `findNexusOperationSlotResult()` | `array{result, failed}` | `History\SlotOutcome` (`result`, `failed`) |
| `findTimerSlotResult()` | `array{id, scheduledAt, failed}` | `History\TimerOutcome` (`timerId`, `failed`) |
| `findChildWorkflowForSlot()` | `array{childExecutionId, result, failed}` | `History\ChildWorkflowOutcome` (`childExecutionId`, `result`, `failed`) |
| `findSideEffectForSlot()` | `mixed` | `?History\SideEffectOutcome` (`result`) |
| `messageAt()` | `array{position, kind, name, payload}` | `History\RecordedMessage` (same fields) |
| `cancellationDelivery()` | `array{position, targets}` | `History\CancellationDelivery` (same fields) |

The classes live in `Gplanchat\Durable\Port\History\`, and each lookup still returns `null` where
it did. To migrate a reader, replace `$x['result']` with `$x->result`, and so on for each field. To
migrate an implementer, return `new SlotOutcome($result, $failure)` where you returned the array.

Two meanings change:

- **The timer's `scheduledAt` is gone.** It was `0.0` on every backend.
- **`findSideEffectForSlot()` distinguishes the two empty cases.** It now returns `null` only when
  nothing is recorded; a recorded `null` is a `SideEffectOutcome` whose `result` is null.
  `hasSideEffectForSlot()` is unchanged.

`ExecutionContext::cancellationDelivery()` follows the port and returns a `CancellationDelivery`.

No Rector rule yet: it ships with the `ExecutionId` type-hints, planned with #269 (the user's
decision of 2026-09-24).

### Laravel: `dispatchNewWorkflowRun()` starts runs on the memory and Temporal backends

**Who is affected**: a Laravel application on the `memory` or `temporal` backend that calls
`WorkflowResumeDispatcher::dispatchNewWorkflowRun()`. It used to return without starting anything
(#603).

**What changes.** On `temporal`, the run now starts on the cluster, as on the Symfony bundle. On
`memory`, the run is now driven inside the call: `dispatchNewWorkflowRun()` returns once the run has
completed, or once it waits on a signal or on something due later than the ten-second drain budget.
A controller that relied on it returning at once will now wait for the run.

**What to do.** Nothing, unless a caller counted on the no-op. On `memory`, move a long run behind a
queued job of your own if the caller must return at once, or switch that environment to
`illuminate`.

### SQL journals: a superseded pass can no longer write, and a new table holds pass epochs (#505, DUR053)

**Who is affected**: applications on the DBAL or Illuminate journal (a new table), and authors of
their own `EventStoreInterface` who want the guarantee. Nothing changes on Temporal.

**Why.** A pass that lost its resume lock, and that a second resume took over, could still append
to the journal. Each pass now claims an epoch for its execution. An append from a pass that a
newer one has superseded is refused with `SupersededPassException`, and the handler stops
without ending the run.

**What to do.**

- **Create the table before you deploy.** The journal refuses to create a missing table inside an
  open transaction. The first write after the deploy may happen in one (a `doctrine_transaction`
  middleware, a `DB::transaction()` around an activity), and it would then fail.
  - **Symfony, DBAL journal**: run `bin/console durable:setup`. With Doctrine Migrations,
    `doctrine:migrations:diff` sees `durable_execution_heads` through the schema listener.
  - **Laravel, Illuminate journal**: run `php artisan migrate`. The new migration adds the table to
    a database that already ran the create migration.
- A claim holds the execution's heads row until its transaction commits. A pass that runs inside
  an outer transaction you opened keeps that lock until you commit, so a newer pass waits for it
  rather than superseding it.
- **Your own store**: nothing breaks. To fence passes, implement
  `Gplanchat\Durable\Store\FencedEventStoreInterface` (`claimPass()` and `appendFenced()`) and
  override `expectsFencedPasses()` to return `true` in your `EventStoreConformanceTestCase`.
  No Rector rule: the storage is yours to write.
- `ExecutionRuntime::checkTimers()` takes an optional second argument, the pass's journal. Existing
  calls keep working.
- `WorkflowBackendInterface::start()` (and `ExecutionEngine::start()`) can throw
  `SupersededPassException` if another pass claims the same execution while it runs.

### Temporal: the `Temporal\Api` classes come from `roadrunner-php/roadrunner-api-dto` (#352)

**Who is affected**: an application that installs `gplanchat/durable-bridge-temporal`, and one
that also installs `temporal/sdk`.

**What changes.** The bridge no longer ships its own generated protobuf classes
(`src/Bridge/Temporal/Api/`, `Generated/`); it requires `roadrunner-php/roadrunner-api-dto` ^1.17,
which provides the same `Temporal\Api\…` and `GPBMetadata\Temporal\…` classes, generated from
Temporal API v1.63.5 (ours were v1.62.7). `temporal/sdk` depends on that same package, so the two
no longer map the same namespaces from two places, where the first autoloader to answer won.

**What to do.** Nothing in code: class names and namespaces are unchanged. Run `composer update
gplanchat/durable-bridge-temporal` so that Composer installs the new dependency, and drop any
autoload mapping of your own that pointed `Temporal\Api\` into the bridge's directory.

### The core reads time through a PSR-20 `ClockInterface` (#617)

**Who is affected**: code that builds `ExecutionRuntime`, `EventStoreCommandBuffer` or
`RunDashboard` itself and passes a clock. Passing `null` or nothing still works. Hosts on the
Symfony bundle, Laravel or Magento have nothing to do.

`gplanchat/durable` now requires `psr/clock`. The core used to read "now" through closures, or
straight from `microtime(true)`. Every class that reads it now takes a
`Psr\Clock\ClockInterface`. The default is the new `Gplanchat\Durable\SystemClock`, the wall
clock in UTC.

| Constructor                                      | Before                                     | Now               |
|--------------------------------------------------|--------------------------------------------|-------------------|
| `ExecutionRuntime`, 5th argument `$clock`        | `?callable` returning a float timestamp    | `?ClockInterface` |
| `EventStoreCommandBuffer`, 4th argument `$clock` | `?callable` returning a float timestamp    | `?ClockInterface` |
| `RunDashboard`, 2nd argument `$now` → `$clock`   | `?\Closure` returning `\DateTimeImmutable` | `?ClockInterface` |

Some constructors gain an optional **last** argument `?ClockInterface $clock`, so existing calls
keep working: `InMemoryEventStore`, `InMemoryWorkflowRunCatalog`, `InMemoryActivityTransport`,
`ActivityMessageProcessor`, `InMemoryWorkflowRunner`, `NativeUuidV7Generator`, and Magento's
`RuntimeFactory`. `ExecutionRuntime::clock()` returns the runtime's clock, and
`ExecutionRuntime::runUntilIdle()` takes the queue's clock as an optional third argument.

**What to do.**

1. Replace a closure clock with a `ClockInterface`. Symfony's `MockClock`, or any PSR-20 clock,
   will do. `$runtime->nowSeconds(...)` handed to a command buffer becomes `$runtime->clock()`.
2. `new RunDashboard($catalog, now: …)` becomes `new RunDashboard($catalog, clock: …)`.
3. The hosts wire the clock for you:
   - **Symfony**: the bundle and the Sylius plugin pass FrameworkBundle's `clock` service when
     one exists, and the core's `SystemClock` otherwise.
   - **Laravel**: the provider binds `durable.clock` to `SystemClock`. Bind your own clock under
     that id to replace it.
   - **Magento**: set `RuntimeFactory`'s `clock` argument in `di.xml`.

Lengths of time are no longer read from a clock. An activity's duration, its start-to-close
bound, and the budgets of the inline drain and of `InMemoryWorkflowRunner` use `hrtime()`
instead. A frozen or skipping clock stops or jumps, and these lengths must not.

`InMemoryWorkflowRunner` (under `WorkflowTestEnvironment`, `DurableTestCase` and Magento's memory
backend) measures schedule-to-start and schedule-to-close on its virtual clock. That clock jumps
to the next timer when nothing else can progress, and moves by the real time the drain spends
waiting out a retry's backoff. Both count towards the bounds. It never follows the wall clock
on its own. Hand the runner the same clock as its activity transport, or delayed retries never
fall due.

A timer that falls due during a backoff is now recorded as fired once the drain is idle. The
activity's remaining attempts still run first, so the timer cannot win against an activity that
is retrying. In `any(activity, timer)`, the losing timer's history changes from
`ActivityCompleted TimerCancelled` to `ActivityCompleted TimerCompleted`.

No Rector rule. The closures being replaced read captured, often mutable, state
(`static fn(): float => $clock->now`). A mechanical rewrite would have to generate a clock
class in your code for every call site. Choosing a clock is a one-line decision, and it is
yours to make.

### Satellites require each other with caret ranges from beta1 on (#347)

From `v0.1.0-beta1` on, a published satellite requires its siblings as `^0.1.0-beta1` (the
caret range on its own tag) instead of `self.version`. `bin/splitsh-publish.sh` rewrites them
when it splits a tag; the monorepo and the satellites' `main` branch keep `self.version`. A
satellite no longer pins its siblings at exactly its own tag, so packages from different tags of
the same line install together. Stability flags still do not propagate: a root on a `stable`
floor keeps `composer config minimum-stability beta` (or a `@beta` flag on every `gplanchat/*`
package it installs, transitive siblings included). Nothing to migrate.

### Only the documented services stay public (#342)

**Who is affected**: an application, or a test booted in an environment without `framework.test`,
that pulls one of the ids below out of the container with `$container->get()` or checks it with
`$container->has()`. Not one that receives them by autowiring: every id below still exists, and
every class or interface id is still an alias of the same service.

Five ids stay **public**, the ones the documentation names and `DurableBundleTestTrait` fetches:
`EventStoreInterface`, `WorkflowMetadataStore`, `WorkflowRunCatalogInterface`,
`WorkflowResumeDispatcher`, and `DurableDataCollector` when the profiler is on.

| Now private                                                                          | Where it exists            |
| ------------------------------------------------------------------------------------ | -------------------------- |
| `Gplanchat\Durable\ExecutionEngine`, `ExecutionRuntime`, `WorkflowRegistry`          | every backend              |
| `Gplanchat\Durable\ActivityExecutor`, `ChildWorkflowRunner`                          | every backend              |
| `Gplanchat\Durable\Port\WorkflowBackendInterface`                                    | every backend              |
| `Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface`                     | every backend              |
| `Gplanchat\Durable\Query\WorkflowQueryRunner`                                        | every backend              |
| `Gplanchat\Durable\Transport\ActivityTransportInterface`                             | every backend              |
| `Gplanchat\Durable\Worker\ActivityMessageProcessor`                                  | every backend              |
| `Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface`                      | every backend              |
| `durable.child_workflow_parent_link_store` (a definition)                            | every backend              |
| `Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface`                         | every backend              |
| `durable.execution_trace` (a definition)                                             | profiler on                |
| `Gplanchat\Durable\Bundle\Handler\ActivityRunHandler`                                | `activity_transport.type: messenger`, no Temporal journal |
| `Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner`                                | a Temporal DSN             |
| `durable.temporal.activity_worker`, `durable.temporal.nexus_worker` (definitions)    | a Temporal DSN             |

**What to do**, in this order:

1. In a service, type-hint the class or interface in the constructor. Autowiring is unchanged.
2. In a `KernelTestCase` or `WebTestCase`, use `static::getContainer()`. The test container reaches
   a private service as long as something injects it. An id nothing injects is removed at compile
   time; `WorkflowBackendInterface` and `WorkflowQueryRunner` are two the bundle never injects.
3. If you must still fetch an id by name, make it public in your own compiler pass. A kernel that
   implements `CompilerPassInterface` is registered as one. The class and interface ids are aliases
   (`getAlias()`); the four marked as definitions in the table take `getDefinition()`:

```php
final class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    public function process(ContainerBuilder $container): void
    {
        foreach ([\Gplanchat\Durable\ExecutionRuntime::class, 'durable.execution_trace'] as $id) {
            if ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            } elseif ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }
}
```

No Rector rule. Turning `$container->get(ExecutionRuntime::class)` into constructor injection adds
a parameter to the caller, and to every place that builds it, and needs the caller to be a service.
That is not a rewrite of one expression, and no rule can find those places.

## 0.1.0-alpha10

### Laravel refuses at boot a workflow whose parameter names diverge from the contract

`gplanchat/durable-laravel` used to register without checking. A workflow carrying
`#[FulfilsNexusOperation]` with a **required** parameter matching no parameter of the contract now
makes registration fail, naming both signatures — the same refusal `NexusHandlerPass` has always
produced on the Symfony side, and from the same class:
`Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames`.

**Why** — a Nexus operation's payload is keyed **by name** at both ends. A parameter renamed on one
side only breaks nothing when written, raises nothing when run, and arrives as `null`: the workflow
starts, runs and returns a result computed on nothing. Registration is the last moment anyone looks.

**What Rector cannot do** — nothing to rename mechanically: the right name is the contract's, and
only the author knows which of the two sides carries the typo. The refusal message prints both
parameter lists, which is exactly the information Rector would need in order to choose.

**Who is affected** — no application whose Nexus operations work: the refusal only strikes
configurations that were already returning `null` in silence. If boot fails after the upgrade, the
fault was already there, without saying so.

## 0.1.0-alpha9

### `RunDashboardView` moves to the core, as `RunDashboard`

**Who is affected**: an application that injected or decorated
`Gplanchat\Durable\Plugin\Dashboard\RunDashboardView`. It was never documented as a user-facing
class, since the Sylius plugin autowires it and its own template consumes it, so most installs
notice nothing.

**What changed**: the Sylius plugin's view model became the core's
`Gplanchat\Durable\Observation\RunDashboard`, which the Magento screen reads too (DUR049). The
class leaves `gplanchat/durable-plugin` for `gplanchat/durable`.

**What to run**: the `durable-upgrade` Rector set renames it. Then clear the container cache
(`bin/console cache:clear`): the compiled container holds the old class name.

### Sylius plugin: the run list pages forward only; `back` is gone

**Who is affected**: whoever links to the plugin's run list (`/admin/durable/runs`) with the `back`
query parameter, or reads the `previous`, `back` and `nextBack` entries of its pagination model in
a template override.

**Why.** Temporal cannot page backwards, so the previous page is dropped (#383, the user's decision
of 2026-09-28): the list pages forward, and a "First page" link leads back.

**What changes.** A URL that still carries `back` redirects permanently (301) to the first page of
the same list, with its status and filters kept, rather than failing. The pagination model carries
`isFirstPage` instead of `previous`, `back` and `nextBack`.

## 0.1.0-alpha8

### The divergence guard compares the payload too

`WorkflowHistorySourceInterface` gains three methods — `activityPayloadForSlot()`,
`nexusOperationPayloadForSlot()` and `childWorkflowInputForSlot()`, all `?array`. The divergence
guard (DUR042) only compared the slot's **identity** — activity name, child type, Nexus triplet; it
now compares the payload as well, on all three. A replay that asks for the same call again with a
different payload raises a `WorkflowTaskFailure` instead of carrying on in silence.

**Why** — the name alone let half the problem through. The journal served the old result, the
freshly computed payload went in the bin, and the execution finished **successfully** having lied
about what it had asked for. Measured on an agent mock-up: nine payloads computed, three journalled,
six divergences swallowed without a word, test suite green.

**What it changes for existing code** — a workflow that is already deterministic sees nothing. A
workflow that built its payload from a clock, a random draw or a read outside the journal now fails
its replay task, naming the byte where the two fingerprints diverge. That is the defect that had to
be seen: those executions were already returning a wrong result.

**What stays out of the guard's reach, deliberately** — the comparison goes through the fingerprint
the journal can hold (JSON round trip, sorted keys). An object the journal retains nothing of — a
DTO with private properties, the house style — therefore does not make a faithful replay diverge. An
unencodable payload (a resource, `NAN`) disarms the guard rather than accusing what it cannot read.
Histories written before this change have nothing to compare and pass unchanged.

**What Rector cannot do** — nothing to rewrite in the calling code. Only third-party implementations
of `WorkflowHistorySourceInterface` have to add the three methods; returning `null` reproduces
exactly the previous behaviour, with no guard on the payload.

**Nexus** — the guard applies there on the Temporal bridge side only, and that is structural: the
journal backend refuses Nexus operations by construction (DUR036), and its `NexusOperationScheduled`
event carries only the call site. No field was added to any event: the three payloads were already
on the wire.


### A workflow that fulfils a Nexus operation must carry its tag

`NexusHandlerPass` used to read the `#[FulfilsNexusOperation]` attributes by **scanning every
definition in the container** and calling `class_exists()` on each one. It now reads the
`durable.nexus_fulfilment` tag, which `DurableBundle::build()` sets from the attribute.

**Why** — the scan loaded every class in the container in order to read its attributes. A single one
extending an absent parent is enough — a half-installed development bundle, and
`Symfony\Bundle\MakerBundle\Maker\AbstractMaker` is the real case that showed it — for the loading
to raise a **fatal error** in a compiler pass that had nothing to do with it. The tag says exactly
what we are looking for, and it already existed for that.

**What Rector cannot do** — nothing to rename: the break is a configuration one.

⚠ **What you have to do, if and only if** one of your workflows carrying
`#[FulfilsNexusOperation]` is declared with `autoconfigure: false`, or built by hand as a
`Definition`. The old scan saw it anyway; the tag does not. The symptom is a refusal at boot, and it
names the operation for you:

```
durable.nexus_handler: operation "encaisser" of contract … is served by nobody
```

Two ways to put it right, depending on what you wanted:

```yaml
services:
    App\Workflow\Encaissement:
        autoconfigure: true          # the tag comes back on its own
```

```yaml
services:
    App\Workflow\Encaissement:
        tags:
            - name: durable.nexus_fulfilment
              contract: 'App\Contract\FacturationContract'
              operation: 'encaisser'
```

### The parameter names of a workflow that fulfils an operation are checked

A workflow carrying `#[FulfilsNexusOperation]` with a parameter **without a default value** matching
no parameter of the contract method now makes container compilation fail.

**Why** — it is the quietest failure mode Nexus has. The payload is keyed by name when written and
read back by name on arrival: a parameter matching nothing received `null`, and the workflow
started, ran and returned a result computed on nothing.

**What you have to do** — if the refusal fires, one of the two sides has a typo. The message gives
both signatures. A parameter the contract deliberately ignores passes if it has a default value:
absence is then a decision, not an oversight.


### Resume orchestration moves down from the bundle into the core

- `Gplanchat\Durable\Bundle\Handler\ResumeWorkflowHandler` → `Gplanchat\Durable\Handler\ResumeWorkflowHandler`
- `Gplanchat\Durable\Bundle\Handler\FireWorkflowTimersHandler` → `Gplanchat\Durable\Handler\FireWorkflowTimersHandler`
- `Gplanchat\Durable\Bundle\Support\AsyncChildWorkflowFailureProjector` → `Gplanchat\Durable\Workflow\AsyncChildWorkflowFailureProjector`

**Why** — this was not a host adapter. Out of **279 lines, 21 touched Symfony** (imports included),
and those 21 served two things only: a v7 id, which `ExecutionId::generate()` already makes in the
core, and "publishing the timer wake-up after the current unit of work". The second became the port
`Gplanchat\Durable\Port\WorkflowTimerDispatcher`, for which the bundle supplies the Messenger
implementation. Six of the selector's hosts do not go through the bundle: leaving it there would
have meant as many copies of the resume semantics, divergent at the first fix.

**What Rector does** — the three renames. **What you have to do** — nothing more, if you were using
these classes indirectly: the bundle still wires them, at the same service ids. The `cache:clear`
remains necessary, for the reason below.

⚠ **If you had your own implementation** of `WorkflowTimerDispatcher` before it existed — impossible,
it is brand new — nothing to do. But if you were injecting a `MessageBusInterface` into a decorator
of these handlers, the seventh argument of `ResumeWorkflowHandler` and the fourth of
`FireWorkflowTimersHandler` are now a `WorkflowTimerDispatcher`, not a bus.

### `TimerWakeDelayCalculator` moves down from the bundle into the core

`Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator` becomes
`Gplanchat\Durable\Timer\TimerWakeDelayCalculator`.

**Why, and why it matters more than the next move** — this class imported nothing from Symfony
(timer events and the event store port), and `InMemoryWorkflowRunner`, which **is** core, called it.
`gplanchat/durable` does not require `gplanchat/durable-bundle`: on any host that does not install
the bundle, a resume that had to jump to the next timer raised a **fatal class-not-found error**.
Under Symfony nothing showed, the bundle always being there.

Found by replaying on Magento an order killed during its reservation. A guard now holds it: no file
in `src/Durable` imports a host or a bridge.

**What Rector does** — the rename. **What it cannot do** — the same `cache:clear` as below, for the
same reason.

### `PayloadToContractMethodInvoker` moves down from the bundle into the core

`Gplanchat\Durable\Bundle\Activity\PayloadToContractMethodInvoker` becomes
`Gplanchat\Durable\Activity\PayloadToContractMethodInvoker`.

**Why** — the class adapts a payload (an array, keys = parameter names) onto the method of an
activity contract. It lived in the Symfony bundle package **without importing a single line of it**,
and the Magento integration needs it word for word: its container has none of Symfony's tags, but
once the contract is resolved the adaptation is the same. It joins `ActivityContractResolver`, which
feeds it and which was already in the core.

**What Rector does** — the rename, everywhere the name appears.

**⚠ What Rector cannot do, and what you have to do by hand** — clear the container cache:

```bash
bin/console cache:clear
```

The fully qualified name is written into the **compiled container**. Without that clear, a Symfony
application keeps asking for the old name after the update, and the failure arrives at the first
activity call — far from its cause, and with nothing pointing at the move. It is also why Composer
cannot warn you: it installs both packages without a word, and the old name simply disappears.

**If you were not using it directly**, you had nothing to do in your code: the class was only
referenced by the bundle's compiler pass. The cache clear, though, remains necessary.

### Every declaration attribute takes the `As` prefix

The repository carried two conventions. The core named its attributes without a prefix
(`#[Workflow]`, `#[Activity]`); the Symfony bundle had a single one, prefixed
(`#[AsDurableActivity]`); neither the Illuminate bridge nor the Magento module had any. Serving
Nexus operations meant adding some, and therefore choosing. `As*` wins, and it says what it says:
*this declaration registers an X*.

**Method** attributes follow the same rule, so that there is one to remember rather than a rule and
its exception.

| before              | after                 |
|---------------------|-----------------------|
| `#[Workflow]`       | `#[AsWorkflow]`       |
| `#[Activity]`       | `#[AsActivity]`       |
| `#[WorkflowMethod]` | `#[AsWorkflowMethod]` |
| `#[ActivityMethod]` | `#[AsActivityMethod]` |
| `#[QueryMethod]`    | `#[AsQueryMethod]`    |
| `#[SignalMethod]`   | `#[AsSignalMethod]`   |
| `#[UpdateMethod]`   | `#[AsUpdateMethod]`   |

**What Rector does** — the rename, everywhere the attribute appears. Nothing else moves: the
arguments, the targets and the meaning of each attribute are unchanged. The set is therefore
replayable without harm.

### `AsDurableActivity` moves down from the bundle into the core, under the name `AsActivityHandler`

`Gplanchat\Durable\Bundle\Attribute\AsDurableActivity` becomes
`Gplanchat\Durable\Attribute\AsActivityHandler`.

Two changes in one, and they justify each other. The move first: this attribute declared that a
class implements an activity contract, which no framework makes specific to itself. Leaving it on
the Symfony side would have forced the Illuminate bridge and the Magento module each to invent
another one to say the same thing. The name second: `AsActivityHandler` pairs it with
`AsNexusServiceHandler`, both declaring an implementation by its contract.

**⚠ What Rector cannot do, and what you have to do by hand** — clear the container cache:

```bash
bin/console cache:clear
```

It is the same trap as for `PayloadToContractMethodInvoker`, only worse: this attribute is **read by
a compiler pass**. The compiled container keeps the fully qualified name, and an application that
upgrades without clearing its cache keeps looking for an attribute that no longer exists — with
nothing pointing at the move.

**If you were not using `#[AsDurableActivity]`**, you have nothing to do; the cache clear is
nevertheless still recommended, since the other entry in this version requires it.

### `JournalExecutionIdResolver::MEMO_KEY_JOURNAL_BOOTSTRAP` is removed

The constant named a memo that a **journal-native bootstrap** would have set — a slab of code that
never reached `main`. Six integration tests described it, four of the five classes they called never
existed, and those tests were deleted with their finding recorded. The constant had outlived them:
nothing read it any more, and its docblock described `workflowType`, which was never its content.

**What Rector does** — nothing. There is no replacement name: this is not a rename but a removal,
and inventing a target would be worse than saying nothing.

**What you have to do** — almost certainly nothing. This constant was read by no code in the
repository. If you reference it, then you were talking to a memo Durable never wrote:
`MEMO_KEY_DURABLE_EXECUTION_ID`, for its part, stays and is indeed the one `WorkflowClient` sets at
start.
