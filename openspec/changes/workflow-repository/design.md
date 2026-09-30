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

Two starts under the same id at the same time: exactly one wins. The journal backends take the
same lock the resume path takes; Temporal decides on the server. The loser gets the same exception
as a second start.

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

### The client port, and what the journal backends gain

A core port, `WorkflowClientPort`, carries the operations keyed by `ExecutionId`: start, signal,
query, update, result, cancel, terminate. Two implementations:

- **Temporal**: the current `WorkflowClient`, re-keyed on `ExecutionId` (it already converts with
  `workflowId()`), with `cancel()` and `terminate()` added through the existing gRPC methods.
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
