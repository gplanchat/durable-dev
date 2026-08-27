# Tasks

## 1. Recover the work rather than rewrite it

- [x] 1.1 Establish that `aa89793` is an ancestor of `main` and that its file is not — the design
      was dropped by a merge resolution, not by a verdict.
- [x] 1.2 Re-run the caller inventory on today's `main`: no new caller of the three methods has
      appeared since.
- [x] 1.3 Cherry-pick `aa89793` and resolve the six conflicts (table in `design.md`).

## 2. The registry leaves the environment

- [x] 2.1 `QueryHandlerRegistry`, `@internal`, carried by `ExecutionContext`.
- [x] 2.2 `WorkflowFiberDriver` passes it as a second argument to the handler; one-parameter
      closures keep working, unmodified.
- [x] 2.3 `WorkflowDefinitionLoader` registers `#[QueryMethod]` handlers into it.
- [x] 2.4 `registerQueryHandler()`, `hasQueryHandler()` and `callQueryHandler()` leave
      `WorkflowEnvironment`.

## 3. The Temporal bridge reads from it

- [x] 3.1 `WorkflowTaskResult` carries the registry, keeping the protocol-messages field.
- [x] 3.2 `WorkflowTaskProcessor::handleQueries()` takes the registry.
- [x] 3.3 Verify both FAILED branches survive: unknown query and throwing handler.
- [x] 3.4 Verify the query path still journals nothing.

## 4. Pin the decision

- [x] 4.1 A test asserting the three methods are off the surface.
- [x] 4.2 A test asserting `onSignal()` and `onUpdate()` stay on it — so the asymmetry reads as a
      decision.
- [x] 4.3 The bridge test that registered a query from a closure becomes a class with
      `#[QueryMethod]`.

## 5. Say it in the documentation

- [x] 5.1 DUR040.
- [x] 5.2 Amend DUR035: the symmetry it drew holds for signals and updates, not for queries.
- [x] 5.3 DUR034 loses `registerQueryHandler()` from its deferral list.
- [x] 5.4 DUR039 gains a forward pointer from its "Not decided here".
- [x] 5.5 `documentation/user/workflows/` stops offering an imperative form for queries, and says
      what that costs a closure-shaped workflow.
- [x] 5.6 `documentation/INDEX.md`.

## 6. Verify

- [x] 6.1 Unit suite green (526 tests).
- [x] 6.2 PHPStan clean.
- [x] 6.3 `php-cs-fixer` clean.
- [ ] 6.4 Integration suite against a real Temporal server. **Not run here** — needs a live server.
      `aa89793`'s twenty green integration tests were against a different `main`; they are evidence
      about the wire format, which has not moved, and not about this tree.
