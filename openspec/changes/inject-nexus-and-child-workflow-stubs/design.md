## Context

`WorkflowDefinitionLoader` builds a workflow's arguments once, at registration, in
`planArguments()`: one closure per parameter, which later produces the argument from the
environment and the input. Two kinds of parameter are supplied today, `WorkflowEnvironment` and an
`ActivityStub` marked `#[Activities]`. `isInjected()` answers "does the loader supply this
parameter?" for every reader of a workflow signature. There are four:

- `inputParameters()`, the parameters a caller passes when it starts the workflow. It calls
  `isInjected()` directly, and the next two readers go through it;
- `ChildWorkflowStub::argumentsToInput()`, the payload a parent sends to a child, through
  `inputParameters()`;
- `NexusFulfilmentParameterNames`, the names a fulfilling workflow must share with its contract,
  through `workflowMethodParameters()`, which calls `inputParameters()`;
- `SchedulingMethodReflection::callerParameters()` in the PHPStan extension
  (`src/DurablePhpstan/Reflection/SchedulingMethodReflection.php`, line 84 on `main`), the
  parameters PHPStan checks on a call through a child workflow stub. It calls `isInjected()`
  directly.

Every host registers workflows through `WorkflowRegistry::registerClass()`, which calls
`load()` on the registry's loader, or on a `new WorkflowDefinitionLoader()` when the registry was
built without one (`WorkflowRegistry` line 37). What the loader supplies, every host supplies. The
loaders that call `load()` today, and when they run:

| Host | Where `load()` runs | Loader | When |
|---|---|---|---|
| Symfony, Sylius | `WorkflowPass` | its own `new WorkflowDefinitionLoader()` (line 28) | container compilation |
| Symfony, Sylius | the `durable.workflow_registry` service | the `durable.workflow_definition_loader` service | the first time the container builds the registry, in each process |
| Laravel | the `WorkflowRegistry` singleton (`DurableServiceProvider::bindWorkflowRegistry()`) | none: `new WorkflowRegistry()`, so the fallback | the first time the container resolves the registry, in each process: the resume handler, the Temporal assembly and `DeclaredWorkflowTypes` ask for it. Not at boot |
| Magento, memory | `RuntimeFactory::create()` | none: `new WorkflowRegistry()` (line 179), so the fallback | each call to `create()` |
| Magento, Temporal | `RuntimeFactory::assembly()` | none: `new WorkflowRegistry()` (line 346), so the fallback | the first call that needs the cluster: the event store when a DSN is set (so `create()` too), the run catalogue, the workers, `workflowClient()` |

`RuntimeFactory::assembly()` also hands a second `new WorkflowDefinitionLoader()` (line 350) to
`TemporalRuntimeAssembly`, which the Temporal worker passes to each `WorkflowEnvironment`.
`WorkflowEnvironment::childWorkflowStub()` falls back to `new WorkflowDefinitionLoader()` (line 519)
when it was built without one.

Other loaders on `main` never call `load()`: `NexusFulfilmentParameterNames`,
`NexusHandlerDeclarations`, `NexusHandlerPass` and the resume dispatchers only read a workflow
type or its parameter names. They need no endpoint resolver.

`WorkflowEnvironment::nexusStub(string $contract, NexusEndpoint|string $endpoint, ?NexusOperationTimeouts $timeouts)`
requires the endpoint. `WorkflowEnvironment::childWorkflowStub(string $class, ?ChildWorkflowOptions $options)`
fixes the options at construction. When `workflowId` is null, the child gets a UUID v7 generated
on the first pass (`ExecutionContext::uuid()`); a replay reads it back from the journal instead of
generating a new one.

## Goals / Non-Goals

**Goals**

- A workflow method receives Nexus stubs and child workflow stubs as arguments, the way it receives
  activity stubs.
- A Nexus endpoint stays a deployment fact: the attribute may name one, the host configuration
  names it otherwise.
- A child's workflow id can depend on the input without giving up the injected stub.
- A mistake fails at registration, naming the parameter, as `#[Activities]` does.

**Non-Goals**

- Removing or deprecating the explicit `nexusStub()` and `childWorkflowStub()` calls.
- Per-call memo or search attributes on a child.
- Any change to the commands sent to Temporal or to the events journaled.

## Decisions

### Two attributes, named after what the stub gives access to

`#[NexusOperations(Contract::class, ...)]` on a `NexusStub` parameter, and
`#[ChildWorkflow(Workflow::class, ...)]` on a `ChildWorkflowStub` parameter.

`#[Activities]` names what the stub schedules; `NexusOperations` follows it. `ChildWorkflow` is
singular because one stub starts executions of one workflow class.

Rejected: a single `#[Stub(Contract::class)]` whose meaning depends on the parameter's type. It
saves one attribute and loses the per-kind options: a Nexus stub takes operation bounds, a child
takes a parent close policy, and one attribute carrying both sets would accept combinations that
mean nothing.

### Attribute arguments are constants, so options are scalars

As in `#[Activities]`, durations are seconds and enums are cases:

```php
#[NexusOperations(StockContract::class, endpoint: 'demo-shop-stock', scheduleToClose: 300.0)]
NexusStub $stock,

#[ChildWorkflow(ShipWorkflow::class, parentClosePolicy: ParentClosePolicy::Abandon, workflowIdReusePolicy: WorkflowIdReusePolicy::RejectDuplicate)]
ChildWorkflowStub $ship,
```

`#[NexusOperations]` takes `endpoint`, `scheduleToClose`, `scheduleToStart`, `startToClose`.
`#[ChildWorkflow]` takes `parentClosePolicy`, `workflowIdReusePolicy`, `taskQueue`, `namespace`,
`executionTimeout`, `runTimeout`, `taskTimeout`. The value objects (`NexusOperationTimeouts`,
`WorkflowTimeouts`, `ChildWorkflowOptions`) are built from them at registration, so their own
checks run there. `NexusOperationTimeouts` already throws on bounds the server would clamp
silently; that failure moves from the first call to registration.

### Child options: honoured by the backend in use, or a registration error

The rule for this change: the same API and the same behaviour on every backend, Nexus limits
excepted. An option declared on `#[ChildWorkflow]` is honoured by the backend in use, or
registering the workflow fails, naming the parameter, the option and the backend. It is never
journaled and then ignored.

What each backend does with each field of `ChildWorkflowOptions` on `main`:

| Option | Temporal: sent in the start-child command | Journal backends (memory, DBAL, Illuminate) |
|---|---|---|
| `parentClosePolicy` | sent (`TemporalWorkflowCommandBuffer`, line 203) | honoured: `ParentChildWorkflowCoordinator` terminates, cancels or abandons the child when the parent closes |
| `workflowIdReusePolicy` | sent (line 204) | honoured: `ExecutionContext::assertChildWorkflowIdAllowed()` checks the journal before the start |
| `taskQueue` | sent (line 188) | recorded in `ChildWorkflowScheduled`, not applied: the child runs where the parent runs |
| `namespace` | sent (lines 192-193) | recorded, not applied |
| the three timeouts | sent (line 198) | recorded, not enforced |
| `cronSchedule` | sent (lines 195-196) | recorded, not applied |
| search attributes | sent (line 199) | recorded |
| memo | not sent: the buffer sets a memo on continue-as-new only | recorded |
| `staticSummary`, `staticDetails` | not sent: the command carries no user metadata | recorded |

"Recorded" means `EventStoreCommandBuffer::scheduleChildWorkflow()` writes the value into the
event's metadata through `ChildWorkflowOptions::toSchedulingMetadata()`, and no code reads it back
to act on it.

What `#[ChildWorkflow]` does with that:

- **In the attribute, honoured everywhere:** `parentClosePolicy`, `workflowIdReusePolicy`.
- **In the attribute, Temporal only for now:** `taskQueue`, `namespace`, `executionTimeout`,
  `runTimeout`, `taskTimeout`. On a journal backend, registering a workflow that declares one of
  them fails, naming the parameter, the option and the backend. The options-parity change that
  PR #782 names as its successor (its design, "Non-Goals": "Parity of the options a workflow
  passes to its activities and children (next change)"; its task 7.2) closes that gap; each
  option it makes a journal backend honour leaves this list.
- **Not in the attribute:** `cronSchedule`, because under PR #782 a start with a cron schedule fails on
  the journal backends until the same options-parity change, and a child on a schedule is a start
  option of its own rather than a declaration of the parent. `staticSummary` and `staticDetails`,
  because the Temporal backend does not send them: accepting them would mean failing on Temporal,
  or changing the start-child command, which this change leaves as it is. Memo and search
  attributes, because their values are usually computed at run time (Non-Goals).

To fail at registration, the loader needs to know the backend. Each host passes it, with the child
options that backend honours, through the same wiring as the endpoint resolver (the table in
Context). A loader built without that information, the fallbacks and the test harness, applies the
journal backends' list: that is what the memory test harness runs.

Failing at the start of the child, as PR #782 does for the start options of a top-level
execution, was rejected here: an attribute argument is a constant, known at registration, and a
start-time failure would surface inside a running parent, possibly days after a deploy.

The explicit `$env->childWorkflowStub($class, $options)` keeps its current behaviour in this
change. Making it fail on an option the backend does not honour changes code that runs today, and
belongs to the options-parity change, with its migration note.

### The endpoint: attribute first, then host configuration, else a registration error

```yaml
# config/packages/durable.yaml
durable:
    nexus:
        endpoints:
            Gplanchat\Durable\Demo\Contracts\Stock\StockContract: demo-shop-stock
```

Resolution order for an injected stub: the attribute's `endpoint`, then the configured endpoint for
that contract, then an error at registration that names the parameter, the contract, and the
configuration key of the host in use.

The resolver is a port of the core, `NexusEndpointResolver`, with one method from contract class to
endpoint or null. Each host builds it from its own configuration and hands it to every loader in
the table above, and to each `WorkflowEnvironment` for `nexusStub()` without an endpoint. The
loader the environment falls back to in `childWorkflowStub()` only reads the child's type and entry
method, never calls `load()`, and needs no resolver. The loader resolves at
registration, so a missing endpoint is found:

- on Symfony and Sylius, at container compilation, by `WorkflowPass`;
- on Laravel, the first time a process resolves the workflow registry. A worker finds it when it
  handles its first job, not when the application boots;
- on Magento, in `create()` for the memory path, and at the first call that builds the Temporal
  assembly for the Temporal path.

It is never found by a workflow execution: registration fails before any execution of the
workflow can run in that process. A loader built without a resolver (the fallbacks above, a test
harness) resolves no endpoint from configuration: an injected stub without an endpoint in its
attribute fails at registration there, and the error names the attribute's `endpoint` argument,
since no host configuration is in play.

`$env->nexusStub()` gets the same fallback: `$endpoint` becomes optional, and when it is omitted
the environment asks the same resolver. That call runs inside a workflow, so a missing endpoint
there fails at the call, naming the contract and the configuration key. Making the parameter
optional is additive: every existing call passes it.

Rejected: an endpoint named after the contract by convention (for example the service name). The
service name is declared by the contract, and the endpoint is chosen by whoever deploys; tying one
to the other is the coupling the docblock warns against.

Rejected: resolving the endpoint at run time, on every call. It would make a configuration mistake
a runtime failure of the first execution that reaches the call, possibly days after a deploy.

### A per-call workflow id: `withWorkflowId()`

```php
return $env->await($ship->withWorkflowId("ship-{$orderId}")->run($orderId, $slot));
```

`withWorkflowId(string $id): static` returns a new stub whose options are the injected ones with
`workflowId` replaced. The injected stub is unchanged, so a workflow can start several children
from one parameter.

The id is computed by workflow code from the input, so a replay computes the same one. It travels
in the command exactly as an id set in `ChildWorkflowOptions` does today; nothing new is journaled.

The stub dispatches the child's entry method through `__call`. A real method named
`withWorkflowId` shadows an entry method of the same name. When a workflow declares a
`#[ChildWorkflow]` parameter for a class whose entry method has that name, registering that
workflow fails, naming the parameter, the child class and the method. The child class itself stays
valid: it can still be started by hand, or run as a top-level workflow.

Rejected for now: `withOptions(ChildWorkflowOptions)`. It would cover memo and search attributes
computed at run time, and it would also let a call override the policy the attribute declared,
which makes the attribute a default rather than a declaration. The explicit
`$env->childWorkflowStub($class, $options)` already covers that case. It can be added later without
breaking anything.

### One place decides what is injected

`isInjected()` gains the two parameter types, so the four readers listed in Context stop counting
them as input without any change of their own. The PHPStan reader runs in the analyser's process,
where no host configuration exists; it needs only `isInjected()`, which reads the parameter's
type and attributes, never the endpoint. A parameter typed `NexusStub` or
`ChildWorkflowStub` without its attribute is refused at registration, as an `ActivityStub` without
`#[Activities]` is today, because the loader cannot tell which contract or class to stub.

### PHPStan

`StubMethodsExtension` already resolves `NexusStub<Contract>` and `ChildWorkflowStub<Workflow>`.
`ActivitiesParameterRule` is generalised to the three attributes: the `@param` docblock must name
the class the attribute names, and a missing one is reported. Its identifiers on `main` are
`durable.activities.missingGeneric` and `durable.activities.contractMismatch`; the two new
attributes get `durable.nexusOperations.*` and `durable.childWorkflow.*` with the same two
suffixes, so a project ignores them the same way.

`withWorkflowId()` is a real method of `ChildWorkflowStub`, declared `@return static`, so the
generic survives the call. The extension resolves a method of the child class marked
`#[AsWorkflowMethod]`, so a child whose entry method is named `withWorkflowId` would reach it;
that child fails registration (task 1.6), and task 3.2 makes the extension leave the name to the
native method in every case.

## Probed and assumed

Nothing in this change is sent to the server differently, so no server behaviour is newly relied
on.

- **Assumed, unchanged:** a child started with an explicit workflow id behaves on Temporal as it
  does today with `ChildWorkflowOptions::$workflowId`. `withWorkflowId()` produces the same
  command.
- **Assumed, unchanged:** the server applies the task queue, namespace and timeouts sent in the
  start-child command. The explicit form sends them today; nothing in the repository probes a child
  in another namespace. The tasks probe it (2.9) before the documentation states it.
- **Assumed, unchanged:** the server clamps Nexus bounds it does not accept.
  `NexusOperationTimeouts` already encodes what was probed about that; this change only calls it
  earlier.
- **To probe in the tasks:** that a workflow using the three injected stubs together replays to the
  same commands as the same workflow with hand-built stubs, on the Temporal backend. This is a
  regression check, not a new server rule.

## Risks

- **An endpoint that changes under an execution in flight.** An operation scheduled before a
  deploy is recorded with its endpoint. If the configuration maps the contract to another endpoint
  after the deploy, a replay of that execution resolves the new one. Whether that is harmless
  depends on how replay matches a recorded Nexus schedule against the command it rebuilds: if the
  endpoint takes part, the replay diverges. Changing a hard-coded endpoint in a deploy has the same
  effect today; configuration makes the change easier to make. The tasks probe it (2.5). If the
  endpoint takes part in the match, the rule to document is the one that already applies to any
  deploy that changes workflow code: an endpoint in use by executions in flight is not changed in
  place; a new endpoint is added and the old one kept until they finish.
- **Several stubs for one contract** are allowed: two parameters may name the same contract with
  two endpoints, and each keeps its own. Nothing is shared between them.

- **A child option that works on Temporal and fails registration on a journal backend** makes a
  workflow that declares a task queue or a timeout untestable with the memory test harness until
  the options-parity change lands. The error names the option and the backend, so the gap shows
  at registration, where a test sees it.
- **A configuration key on three hosts** is three places to document and test. The tasks require
  one test per host that resolves an endpoint from configuration.
- **An endpoint in the attribute** stays possible, and it is what every current example does.
  The documentation shows the configuration form first, and explains why the endpoint is a
  deployment fact.
