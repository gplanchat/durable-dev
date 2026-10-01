## Context

The client operations exist today in three disconnected places: the engine's port
`WorkflowResumeDispatcher` (start only, untyped), the Temporal adapter `WorkflowClient` (everything
but cancel and terminate, keyed by a Temporal workflow id), and Symfony's bus messages
(`DeliverWorkflowSignalMessage`, `DeliverWorkflowUpdateMessage`, which drop the update's result).
`WorkflowRunCatalogInterface::findRun()` answers "does this execution exist, and what is it" on
every backend. `WorkflowQueryEvaluator::lastExecutionResult()` reads a finished execution's result
from a journal. The journal already has the events cancellation and updates need:
`WorkflowCancellationRequested`, `WorkflowUpdateHandled`, and `WorkflowExecutionFailed` with a
`kind` (one of which, `terminated_by_parent`, already ends an execution from outside it).

## Goals / Non-Goals

**Goals**

- One client API, identical on the four backends and the three hosts.
- A stub typed by the workflow class, for an execution that has not started and for one that has.
- Every start option honoured, or refused when the workflow is started. Never ignored.

**Non-Goals**

- Parity of the options a workflow passes to its activities and children (next change).
- Removing the dispatcher port or the Temporal client.

## Decisions

### The repository is a class the application declares, generic underneath

```php
/** @extends WorkflowRepository<OrderWorkflow> */
#[AsWorkflowRepository(OrderWorkflow::class)]
final class OrderWorkflowRepository extends WorkflowRepository
{
    public function forOrder(string $orderId): WorkflowStub
    {
        return $this->get(ExecutionId::fromString("order-{$orderId}"));
    }
}
```

`WorkflowRepository` is an abstract class of the core. Its constructor takes the client port and
the workflow registry, both core services, so a subclass is injectable on every host with no
declaration. The attribute says which workflow class the repository serves; at registration, the
host refuses a repository whose workflow is not registered. A subclass is also where finders named
after the domain go.

Rejected: a repository interface generated per workflow. Code generation would differ on each host
and leave a class nobody reads.

### `#[RepositoryFor]`: one core attribute, resolved by the two hosts that can

```php
public function __construct(
    #[RepositoryFor(OrderWorkflow::class)] private WorkflowRepository $orders,
) {}
```

The attribute is a plain class of the core, with no framework dependency. Each host resolves it
its own way:

- **Laravel**: `Container::whenHasAttribute(RepositoryFor::class, ...)` builds the generic
  repository for the named workflow.
- **Symfony**: a compiler pass reads the constructor parameters of autowired services and sets the
  argument of each one carrying the attribute to a generic repository service for that workflow.
  Sylius inherits it through the bundle.
- **Magento** cannot resolve a parameter attribute: its object manager reads `di.xml`, not
  attributes. The declared class is the form there. This is a host gap the owner accepted, and it
  is documented as such; it is not a backend gap.

The two forms do not compete. A declared repository is injected by its own type; the attribute
asks for the generic `WorkflowRepository` type. A service that wants the domain finders of a
declared repository asks for that class.

Both mechanisms are verified on the lowest supported lines before any code (tasks, section 0):
`whenHasAttribute()` exists on Laravel 12.68, the bench's line, and must be confirmed on 11; the
compiler pass must run on Symfony 6.4.

### `create()` and `get()`

`create(?ExecutionId $id = null, ?WorkflowStartOptions $options = null): WorkflowStub` contacts no
backend. It returns a stub bound to the id, generated (UUID v7) when none is given, and to the
options. `get(ExecutionId $id): WorkflowStub` asks the run catalogue: an unknown execution throws
`WorkflowExecutionNotFound`; one of another workflow type throws `WorkflowTypeMismatch`. Both name
the id.

### `start()` and `execute()`, never the entry method

`$stub->start(...$input): WorkflowStub` starts the execution and returns the stub, now bound to a
started execution. `$stub->execute(...$input): mixed` starts it and waits for the result.

Two starts under the same id at the same time: exactly one wins, and the loser gets the same
exception as a second start. See "Two starts of one id" below for what serialises them on each
backend.

The arguments are mapped to the workflow's input by parameter name, the way a parent's call on a
child stub is mapped today. PHPStan types them from the entry method's signature. Calling
`start()` on a stub from `get()`, or twice, throws: the execution already started. A start that
the id reuse policy refuses throws the same exception on every backend.

Rejected: calling the entry method on the stub. `$stub->run($orderId)` reads as if it ran the
workflow in this process; two explicit verbs say whether the call waits.

### A running execution is reached through the workflow's own methods

On a stub for a started execution, a call to a method the workflow declares with
`#[AsSignalMethod]` sends the signal; `#[AsQueryMethod]` returns the query's answer;
`#[AsUpdateMethod]` returns the update's result. Any other name throws, naming the class and the
method. `result(?Duration $timeout = null)` waits for the result and throws
`WorkflowResultTimeout` when the bound elapses. A failed execution throws its failure, typed as
the journal records it.

### Method names the stub keeps for itself

The stub's own verbs share the namespace of the workflow's signal, query and update methods:
`start`, `execute`, `result`, `cancel`, `terminate` and `executionId`. A workflow that declares one
of them with `#[AsSignalMethod]`, `#[AsQueryMethod]` or `#[AsUpdateMethod]` could never be reached
through that method. The host checks this when it registers a repository, in both forms (a declared
class and `#[RepositoryFor]`), and fails the boot with an exception that names the workflow class
and the method. PHP method names are case-insensitive, so the comparison is too: `Cancel()`
collides with `cancel()`.

Rejected: prefixing the stub's verbs (`__start()`, `stubResult()`). Every call site would pay for a
collision that a rename in the workflow avoids once.

### The client port, and what the journal backends gain

A core port, `WorkflowClientPort`, carries the operations keyed by `ExecutionId`: start, signal,
query, update, result, cancel, terminate. Two implementations:

- **Temporal**: the current `WorkflowClient`, re-keyed on `ExecutionId` (it already converts with
  `workflowId()`), with `cancel()` and `terminate()` added through the existing gRPC methods, and
  a start that reports an already started execution instead of returning (see "Starting twice").
- **Journal** (in-memory, DBAL, Illuminate):
  - *Signal* and *update*: appended through the same path the Symfony bus handlers use today, then
    a resume is dispatched. The update's result is read back from `WorkflowUpdateHandled`.
  - *Query*: a read-only pass. The workflow is rebuilt by replaying its journal to its current
    suspension point, with a command buffer that refuses any write, and the query handler is
    called on it. Determinism is what makes the state reached equal to the live one.
  - *Result*: `ExecutionCompleted` or `WorkflowExecutionFailed`, polled with the bound given.
  - *Cancel*: `WorkflowCancellationRequested` appended, then a resume, so the workflow receives the
    cancellation where it waits, as it does on Temporal.
  - *Terminate*: a new `WorkflowExecutionFailed` kind, `terminated`, appended without running any
    more workflow code, as `terminated_by_parent` does.

### Ending an execution that has already ended

`cancel()` and `terminate()` on an execution that has ended throw
`WorkflowExecutionAlreadyEnded`, naming the id, and change nothing. Temporal answers the same case
with an error the adapter maps to that exception (probed in 0.2).

### Starting twice: a behaviour change on every backend

No backend fails a second start today.

- **Temporal**: `WorkflowClient::doStartWorkflow()` catches the `StartWorkflowExecution` error,
  and when `isWorkflowAlreadyStartedGrpcError()` matches (gRPC code 6, or a message containing
  "already running") it returns as if the start had succeeded (`src/Bridge/Temporal/WorkflowClient.php`,
  lines 373 to 381 on `main` at c908668a). `startAsync()` then returns the execution id, and
  `startSync()`, which calls `doStartWorkflow()` too, waits for the running execution.
- **Journal backends**: every dispatcher's `dispatchNewWorkflowRun()` calls
  `WorkflowMetadataStore::save()`, an upsert on DBAL and Illuminate and an array write in memory.
  A second start overwrites the first one's workflow type and input, resets `completed`, and
  dispatches a resume.

The client port fails the second start with the "already started" exception, naming the id, and
leaves the first execution as it was. The change is scoped to the port. The engine's own path,
`WorkflowResumeDispatcher::dispatchNewWorkflowRun()`, keeps its current behaviour, because a
Messenger or queue redelivery of a start must stay harmless; on Temporal that path goes through
`TemporalWorkflowResumeDispatcher` to `startAsync()`. The swallowing moves out of
`doStartWorkflow()` into its two public callers, `startAsync()` and `startSync()`, which keep their
behaviour, and the port calls `doStartWorkflow()` through a variant that does not swallow.

Code 6 is `ALREADY_EXISTS`. It is assumed, not probed, that the server also answers a start the id
reuse policy refuses with code 6. If it does, Temporal ignores reuse refusals today, which breaks
"honoured or refused" on the one backend that claims to honour the policy. Task 0.2 probes it.

### Two starts of one id

Nothing serialises two starts today. A start writes the metadata row, then dispatches a resume;
the resume lock is taken by the consumer of that resume, after the write:

| Backend and host | Lock held around a resume today | Around a start |
|---|---|---|
| DBAL on Symfony | `SingleResumeLockMiddleware`, a Messenger middleware on `symfony/lock` | none |
| Illuminate on Laravel | `ResumeLock`, on the cache store `durable.lock.store` names | none |
| In-memory (Symfony, Laravel, Magento) | none: one process | none |
| Temporal (Symfony, Laravel, Magento) | the server | the server |

What this change adds, on the SQL backends: the port's start inserts the metadata row instead of
upserting it. `execution_id` is already the primary key of `durable_workflow_metadata`
(`DurableSchema`, and `->primary()` in the Illuminate migration), so the database serialises two
starts and the second insert fails on the duplicate key, which the port reports as an already
started execution. No lock store is involved, so the Laravel `durable.lock.store` setting has no
bearing on it. A start that the id reuse policy allows after an ended execution is a conditional
`UPDATE ... WHERE execution_id = ? AND completed = true`: one affected row wins, zero means another
start came first. Every ending path of `ResumeWorkflowHandler` marks the row completed today
(success, failure, cancellation, continue-as-new); the new `terminated` kind must as well.
`completed` changes from true to false in that update, so MySQL's count of
changed rows and SQLite's count of matched rows agree. The continue-as-new path keeps calling the
upsert.

In memory, an execution lives in one process, so two processes cannot start the same id; the port
checks the map before writing. Magento runs the in-memory and Temporal backends on `main`, and its
README states that no SQL journal runs on `ResourceConnection`.

### Correlating an update with its result

`WorkflowUpdateHandled` carries the update's name, arguments, result and failure, and no id. A
pending update reaches the journal the same way: `PendingUpdate` holds a name and arguments, the
`$pendingUpdates` shape of `dispatchResume()` and `ResumeWorkflowMessage` is
`list<array{name, arguments}>`, and `DeliverWorkflowUpdateHandler` drops the `updateId` that
`DeliverWorkflowUpdateMessage` already carries. Two concurrent updates with the same name and the
same arguments therefore write two events the client cannot tell apart, and each caller may read
the other's result.

The event gains an update id, set by the client when it sends the update and carried through
`PendingUpdate`, the `$pendingUpdates` shape, `EventStoreCommandBuffer::recordUpdateHandled()` and
`EventDataMapper`. The client waits for the event with its own id. It follows the house precedent
for a field added to a journal event (`WorkflowSignalReceived::requestId`,
`WorkflowContinuedAsNew::newExecutionId`): nullable on read.

- **Journals written before the change**: an event without an id replays as it does today. No
  client call waits on it, since the client did not exist when it was written, so the client never
  matches an event whose id is null.
- **Messages queued before the upgrade**: a pending update without an id is handled and journaled
  with a null id. Nobody waits for it through the port; a Symfony caller that sent it through
  `DeliverWorkflowUpdateMessage` did not receive the result before either.
- **Temporal**: `WorkflowClient::update()` already takes `$updateId` and sends it as
  `Meta.update_id`, and `UpdateProtocol` answers the server with it. The gap is on replay:
  `TemporalExecutionHistory` pairs each `WORKFLOW_EXECUTION_UPDATE_COMPLETED` event with the last
  accepted update rather than by id. The completed event carries `meta` (with the update id) and
  `accepted_event_id`, so the pairing is made on them.

### An execution that continued as new

The backends name the next run differently. On the journal backends,
`EventStoreWorkflowLifecycle::onContinuedAsNew()` generates a new execution id and journals it as
`WorkflowContinuedAsNew::newExecutionId`; on Symfony and Laravel, `ResumeWorkflowHandler` then
marks the old execution completed and starts the next run under that id. Magento's in-memory
backend runs `InMemoryWorkflowRunner`, which does not catch `ContinueAsNewRequested`: the event is
journaled, the exception reaches the caller, and no next run starts. That is a host gap of its own,
read on `main` and not run. On Temporal, the next run keeps the workflow id and gets a
new run id; the command buffer writes the `durableExecutionId` memo onto it (#560). This is a parity
gap between backends: `backend-data-parity` lists linking the runs of a chain on the SQL backends as
tracked separately (proposal, "Not in scope"). This change does not close it.

The stub follows the chain, so the application sees the same outcome on every backend:

- `result()` waits for the result of the last run of the chain. On the journal backends it follows
  `newExecutionId` from each `WorkflowContinuedAsNew` it meets; on Temporal the server's current run
  under the workflow id is already the last one.
- `cancel()` and `terminate()` act on the current run of the chain, found the same way. They throw
  "already ended" only when the last run has ended.
- On Magento's in-memory backend, where the next run named by `newExecutionId` never started, the
  three calls fail with an exception that names both ids.
- A journal written before #322 has `WorkflowContinuedAsNew` without `newExecutionId`. There, the
  three calls fail with an exception that names the execution and says the continuation cannot be
  followed. They do not wait for the bound.

### What `get()` needs from `backend-data-parity`

`get()` reads `WorkflowRunCatalogInterface::findRun()` and `WorkflowRunDescription::executionId`.
Both are on `main` (c908668a) on all four backends, so `get()` does not wait for the open change
`openspec/changes/backend-data-parity`. On Temporal, `findRun()` matches on the `durableExecutionId`
memo, and the command buffer writes it onto a continued run, so `get()` finds the current run of a
chain without parity probe 0.4. The tasks of that change not yet checked off (the cursor, the
refusal of execution ids the Temporal derivation would alter) do not change what `get()` returns.

### Start options on the journal backends

| Option | Journal backends in this change |
|---|---|
| id reuse policy | honoured: checked against the catalogue at start |
| execution and run timeouts | honoured: a timer journaled at start ends the execution |
| task timeout | refused: no workflow task exists outside Temporal |
| task queue | refused until the options-parity change maps queues to transports |
| search attributes | refused until the options-parity change stores and filters them |
| cron schedule | refused until the options-parity change schedules restarts |

A refusal is one exception at `start()`, naming every refused option and the backend. It is a temporary gap
that the next change closes, stated in the docs as such.

## Probed and assumed

- **To probe first**: Temporal's answer to a query on a closed workflow, and to an update on a
  workflow that is completing. The client must return the same outcome from the journal backends.
- **To probe first**: `RequestCancelWorkflowExecution` and `TerminateWorkflowExecution` through the
  existing gRPC methods, on the server version CI runs (1.32) and on the dev server (1.25.2).
- **To probe first**: the gRPC code and message of `StartWorkflowExecution` for a workflow id that
  is running, and for one the id reuse policy refuses after it ended. The design assumes code 6
  for both.
- **To probe first**: that `WorkflowExecutionUpdateCompletedEventAttributes.meta.update_id` holds
  the id the client sent. The field exists in the protocol; its content is not probed.
- **Read, not probed**: the swallowing of an already started workflow, the metadata upsert, the
  resume locks, the continue-as-new paths and the memo on a continued run are read from `main` at
  c908668a.
- **Assumed**: the read-only replay reaches the same state as the live execution. That is the
  determinism rule every workflow already follows. A workflow that breaks it breaks its own replay
  too.

## Risks

- **A query costs a replay on the journal backends**, proportional to the history's length.
  Temporal pays the same on a cold worker. The docs say so.
- **`execute()` in a web request** waits as long as the workflow runs. The docs show `start()`
  first and reserve `execute()` for commands and short workflows.
- **Laravel's in-memory backend runs a start in the caller's process** (#603), so `start()` returns
  only once the run suspends or ends. That behaviour stays, and the docs state it.
