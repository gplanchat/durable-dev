## Why

Inside a workflow, everything is typed: a child starts through a stub of its class, and its entry
method is called like a method. Outside a workflow, in a controller, a command or a queue job,
nothing is. An audit of `main` (4c98b82b, 2026-09-30) found no client API that works the same on
every backend:

| Operation | In-memory, DBAL, Illuminate | Temporal |
|---|---|---|
| Start, no options | `WorkflowResumeDispatcher::dispatchNewWorkflowRun($id, 'greet', [...])`, on Symfony and Laravel; **none on Magento** | the same call, or `WorkflowClient::startAsync()` |
| Start with options | unavailable | `WorkflowClient::startAsync()`; the interface does not even declare the options parameter the docs pass |
| Signal | a Symfony bus message; **nothing on Laravel or Magento** | `WorkflowClient::signal()`, keyed by a Temporal workflow id, not an `ExecutionId` |
| Update and its result | Symfony bus message, result dropped | `WorkflowClient::update()` |
| Query | **unavailable**: query handlers run only in the Temporal worker | `WorkflowClient::query()` |
| Wait for the result | a read after the fact, on Symfony only | `startSync()`, `pollForCompletion()` |
| Cancel, terminate | **unavailable** | **unavailable** (raw gRPC or the CLI) |

The documentation tells readers to write Temporal-only code for half of these, and claims parity
where there is none (`backends/_index.md`: "Signals, updates, queries ✅ ✅ ✅ ✅").

The rule this change applies, set on 2026-09-30: **an application uses the same API whatever the
backend and whatever the host.** Switching the backend is a configuration change. The one allowed
exception is a functional limit of Nexus, and no client operation touches Nexus.

## What Changes

- **A repository per workflow class.** An application declares one per workflow it drives:

  ```php
  /** @extends WorkflowRepository<OrderWorkflow> */
  #[AsWorkflowRepository(OrderWorkflow::class)]
  final class OrderWorkflowRepository extends WorkflowRepository
  {
  }
  ```

  It needs no registration on any host: its dependencies are core services, which Symfony, Laravel
  and Magento all inject into a concrete class. The attribute names the workflow and lets the host
  check, at registration, that the workflow is registered too.
- **A repository without a class, on Symfony and Laravel.** A constructor parameter
  `#[RepositoryFor(OrderWorkflow::class)] WorkflowRepository $orders` receives the repository of
  that workflow. Magento cannot resolve a parameter attribute; there, the declared class is the
  only form. This is an accepted host gap, not a backend gap.
- **Two ways in, one stub.** `create(?ExecutionId $id = null)` returns a stub for an execution that
  has not started; `get(ExecutionId $id)` returns a stub for one that has, and fails at once when
  the execution is unknown or belongs to another workflow class. Without an id, `create()`
  generates one, readable on the stub.
- **Two explicit starts.** `$stub->start(...$input)` starts the execution and returns at once;
  `$stub->execute(...$input)` starts it and waits for its result. The workflow's entry method is
  not called on the stub.
- **One stub for a running execution.** Calling a method the workflow declares with
  `#[AsSignalMethod]` sends that signal; with `#[AsQueryMethod]`, runs that query and returns its
  answer; with `#[AsUpdateMethod]`, sends that update and returns its result. `result()` waits for
  the execution's result, with an optional bound. `cancel()` and `terminate()` end it.
- **Start options on every backend.** `create()` takes `WorkflowStartOptions`. Each option is
  honoured on every backend, or refused explicitly when the workflow is started, never ignored.
  Options the journal backends cannot honour yet are listed in design.md, and the backend-parity
  change that follows this one closes them.
- **The journal backends gain what only Temporal had:** queries evaluated by a read-only replay of
  the journal, update results, cancellation and termination requested from outside.
- **Every host wires the same thing.** Magento gains a dispatcher, Laravel gains signal and update
  delivery. On Temporal, `WorkflowClient` is keyed by `ExecutionId` like the rest.
- **Documentation**, EN and FR: one page on starting and reaching a workflow, and every statement
  the audit found wrong is corrected.

### Not in scope

- The options a *workflow* passes to its activities and children (timeouts, heartbeats, task
  queues, child options): the audit found eleven that diverge silently. They get their own change.
- Removing `WorkflowResumeDispatcher` or `WorkflowClient`. They stay, as the engine's port and the
  Temporal adapter; the documentation stops presenting them as the application's API.
- Nexus: no client operation involves it.

## Impact

- `src/Durable`: the repository base class, the stub, the client port, its journal implementation,
  the read-only query pass, two attributes.
- `src/Bridge/Temporal`: the client port's Temporal implementation, `ExecutionId`-keyed.
- `src/DurableBundle`, `src/DurableLaravel`, `src/DurableModule`: repository registration,
  `#[RepositoryFor]` on the first two, signal and update delivery on Laravel, a dispatcher on
  Magento.
- `src/DurablePhpstan`: typed `start()`, `execute()`, signal, query and update calls.
- `documentation/user/`: a new page, and corrections to `backends`, `concepts`, `options`,
  `getting-started`, `packages`, `cancellation`.
- The benches: each one drives its workflows through a repository.
