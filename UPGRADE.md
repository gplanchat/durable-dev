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

### Laravel on Temporal: decorating an intermediate binding no longer reaches the services built from it

**Who is affected**: a Laravel application on the Temporal backend that decorates or rebinds
(`$app->extend()`, a later `singleton()`) one of the Temporal bindings **in order to change the
services built from it**: `TemporalHistoryCursor`, `WorkflowServiceExecutionRpc`,
`WorkflowServiceNexusRpc`, `WorkflowTaskRunner` or `WorkflowClientInterface`.

These services now come from one `Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly` (#356). Every
binding id still resolves, to the assembly's object. But the run catalog, the task processor and
the read-through store are built inside the assembly, not resolved through the container, so a
decorated cursor or RPC is no longer the one they use. The heartbeat sender is the exception: the
activity worker still takes whatever `ActivityHeartbeatSenderInterface` resolves to.

Rector cannot help: this is container wiring. To migrate:

1. To change what a service does, decorate **that** service: `$app->extend(WorkflowRunCatalogInterface::class, …)`
   still wraps the catalog the application resolves.
2. To change what several services share (the cursor, the client), bind an assembly of your own,
   built with your client or connection: `$app->instance(TemporalRuntimeAssembly::class, $assembly)`
   before the services resolve. Every Temporal binding then comes from it.

Symfony and Magento are unaffected: Symfony's services are unchanged here, and Magento never
exposed these objects to its container.

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
changes is the path to get there, not what you get. The rest of the bundle's public surface is
unchanged — the Temporal workers, the parent/child link store, the profiler collector and the engine
classes stay reachable by their id.

Rector can do nothing: rewriting a `$container->get('durable.event_store.dbal')` into an injection
requires knowing where the object is used, which no rule can guess. The table above is the
procedure.

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
$version = $env->version('add-discount', ChangePoint::DEFAULT_VERSION, 1);
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
