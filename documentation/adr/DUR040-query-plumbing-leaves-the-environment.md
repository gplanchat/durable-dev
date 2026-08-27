# DUR040 — Query plumbing leaves the environment

## Status

Accepted

## Context

DUR039 removed the scheduling primitive from `WorkflowEnvironment` and left three methods behind:

| Method | called by | called by a workflow author |
|---|---|---|
| `registerQueryHandler()` | `WorkflowDefinitionLoader`, once, while building the instance | never |
| `hasQueryHandler()` | `WorkflowTaskProcessor`, when a query arrives | never |
| `callQueryHandler()` | `WorkflowTaskProcessor`, same call site | never |

They were left in place for a reason that was about review order, not about design:
`workflow-conditions-and-handler-dispatch` had just landed on `main` and added `onSignal()` and
`onUpdate()` — imperative registration, argued for on the symmetry with `registerQueryHandler()`.
Deleting one half of a symmetry someone had just built, inside a review about activities, would
have settled a question nobody had asked.

This ADR asks it.

## Decision

**A query handler is declared with `#[QueryMethod]` and nothing else.** The registry that holds
them, `QueryHandlerRegistry`, is carried by `ExecutionContext` — the engine-side object a workflow
never receives. The definition loader writes to it when it instantiates the class; the Temporal
task processor reads from it when a query arrives. Neither goes through the workflow's environment.

**`onSignal()` and `onUpdate()` stay public.** The difference is not one of principle but of
**place**:

- a signal or an update is dispatched **inside** `WorkflowEnvironment`, during `await()`, by the
  private `dispatch()`. The object that registers is the object that dispatches;
- a query is read by the **worker**, outside the fiber, between two workflow tasks. The environment
  was only ever a place to park the handlers on the way to somebody else.

That is why the query verbs were reachable from a workflow in the first place: not because a
workflow needed them, but because the environment was the object the engine happened to be holding.

### What made it cheap

`WorkflowFiberDriver` now calls `$handler($environment, $queries)`. **PHP accepts extra arguments
to a userland function**, so every closure in the suite that declares only the environment keeps
working untouched. Only the loader's factory declares the second parameter.

## Consequences

- **Breaking.** `$env->registerQueryHandler()`, `$env->hasQueryHandler()` and
  `$env->callQueryHandler()` stop compiling. The replacement for the first is `#[QueryMethod]`; the
  other two had no author-facing purpose to replace.
- **A closure-shaped workflow can no longer answer a query.** This is the real cost, and it is
  stated rather than hidden: a closure cannot carry an attribute, and the imperative door is now
  shut. A workflow that answers queries is a class. The one test that registered a query from a
  closure became a class with `#[QueryMethod]` — which is also the demonstration that the
  declarative path suffices.
- `WorkflowTaskResult` carries the registry instead of the whole environment. It had been carrying
  an object of which the caller used exactly this plumbing.
- The asymmetry with signals and updates is now **pinned by a test**
  (`ActivitySchedulingPortTest::testSignalAndUpdateRegistrationStayOnTheSurface`), so that a later
  reader finds a decision rather than an oversight.

### On the provenance of this change

The work was written once before, as `aa89793`, and lost — not on its merits but to a merge
resolution where `main` won on queries after `onSignal()`/`onUpdate()` landed concurrently. That
commit passed 376 unit tests and **twenty integration tests against a real Temporal server**. This
change restores it onto a `main` that has since moved, with two adaptations the drift forced: the
`QueryableWorkflow` fixture no longer calls `waitSignal()`, a method the conditions work removed,
and `WorkflowTaskResult` keeps the protocol-messages field it gained in the meantime.

The integration tests need a live server and were **not** replayed here. The green run recorded in
`aa89793` was against a different `main` and is evidence about the wire format, not about this
tree.

## Alternatives considered

- **`onQuery()` — keep registration public, hide only the dispatch pair.** This was the first
  branch considered, on the symmetry argument, and the measurement killed it: `aa89793` had already
  proved the declarative path carries the whole test suite, including the one bridge test that
  looked like it needed the imperative form. Keeping a verb because its neighbours have one is not
  a reason when nothing calls it.
- **Widening `registerQueryHandler()` to `\BackedEnum`,** the deferral DUR034 recorded. Dead work
  on a method being deleted.
- **Leaving all three and documenting them `@internal`.** PHP has no package-private. The same
  objection DUR039 raised applies unchanged: a docblock enforces nothing, and the shorter form wins.

## Related decisions

- **DUR039** — the workflow authoring surface. Its "Not decided here" section is what this ADR
  decides.
- **DUR035** — conditions are the primitive and handlers are dispatched. Its section on imperative
  registration is amended: the symmetry it drew holds for signals and updates, not for queries.
- **DUR034** — signal names as backed enums. `registerQueryHandler()` leaves its deferral list.
- **DUR022** — the workflow class contract and `WorkflowEnvironment`.
