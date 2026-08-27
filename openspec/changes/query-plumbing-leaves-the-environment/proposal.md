## Why

DUR039 removed the scheduling primitive from `WorkflowEnvironment` and left three methods behind —
`registerQueryHandler()`, `hasQueryHandler()`, `callQueryHandler()`. Its "Not decided here" section
says why, and it is a reason about review order rather than about design:
`workflow-conditions-and-handler-dispatch` had just landed on `main` with `onSignal()` and
`onUpdate()`, argued for on the symmetry with `registerQueryHandler()`. Deleting one half of a
symmetry someone had just built, inside a review about activities, would have settled a question
nobody had asked.

The counts have not moved since:

| Method | engine | tests | docs | sample |
|---|---|---|---|---|
| `registerQueryHandler()` | 1 (the definition loader) | 1 | 1 | 0 |
| `hasQueryHandler()` | 1 (the Temporal task processor) | 0 | 0 | 0 |
| `callQueryHandler()` | 1 (same call site) | 0 | 0 | 0 |

No workflow calls them. They are on the environment because the environment is the object the
engine happened to be holding, and a workflow author who found them would be bypassing the
`#[QueryMethod]` declaration the library teaches.

The symmetry with signals and updates is real but shallow, and the difference is one of **place**:

- a signal or an update is dispatched **inside** `WorkflowEnvironment`, during `await()`, by a
  private method. The object that registers is the object that dispatches.
- a query is read by the **worker**, outside the fiber, between two workflow tasks. The environment
  was a parking space on the way to somebody else.

## What Changes

- Declaring a query handler SHALL be done with `#[QueryMethod]`, and there SHALL be no imperative
  form on the surface a workflow author reaches.
- The handlers SHALL be held by a registry carried by `ExecutionContext` — the engine-side object a
  workflow never receives. The definition loader writes to it; the Temporal task processor reads
  from it.
- Registering a **signal** or an **update** handler imperatively SHALL remain available. The
  requirement that describes it SHALL stop citing queries as its precedent, which is no longer true.
- The result the Temporal task runner returns SHALL carry the query registry rather than the whole
  environment, of which the caller used exactly this plumbing.
- **BREAKING** yes. `$env->registerQueryHandler()`, `$env->hasQueryHandler()` and
  `$env->callQueryHandler()` stop compiling. `#[QueryMethod]` replaces the first; the other two had
  no author-facing purpose to replace.

### Not in scope

- `onSignal()`, `onUpdate()`, `hasSignalHandler()`, `hasUpdateHandler()` and the private dispatch
  they feed. Their registration and their dispatch are in the same object, which is the whole
  distinction this change rests on.
- Widening any message-name parameter to `\BackedEnum`, the deferral DUR034 recorded for
  `registerQueryHandler()`. The method is being deleted; the widening has nowhere to land.

## Capabilities

### New Capabilities

<!-- None: both capabilities exist. -->

### Modified Capabilities

- `workflow-authoring-surface`: gains the requirement that query plumbing is not on the surface.
- `workflow-handler-dispatch`: its declaration requirement stops claiming a query can be registered
  imperatively.

## Impact

- **Domain** (`src/Durable`): `QueryHandlerRegistry` is added and carried by `ExecutionContext`;
  three methods leave `WorkflowEnvironment`; `WorkflowFiberDriver` passes the registry alongside
  the environment; `WorkflowDefinitionLoader` registers into it.
- **Temporal bridge** (`src/Bridge/Temporal`): `WorkflowTaskResult` carries the registry;
  `WorkflowTaskProcessor` answers queries from it.
- **Test suite**: one bridge test registered a query from a closure — the form being removed. It
  becomes a class with `#[QueryMethod]`, which is also the demonstration that the declarative path
  suffices.
- **A closure-shaped workflow can no longer answer a query.** This is the cost, and it is stated
  rather than hidden.
- **User documentation**: the workflow surface page stops offering an imperative form for queries.
- **ADR**: DUR040 records the decision; DUR035 is amended, DUR034 loses a deferral, DUR039 gains a
  forward pointer.
- **Dependencies**: none.
