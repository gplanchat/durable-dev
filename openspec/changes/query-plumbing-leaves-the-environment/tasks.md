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
- [x] 3.3 Verify both FAILED branches survive: unknown query and throwing handler — each has a test.
- [x] 3.4 Assert the query path changes nothing: the same task, polled with and without a query, produces the same commands.

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

- [x] 6.1 Unit suite green (528 tests).
- [x] 6.2 PHPStan clean.
- [x] 6.3 `php-cs-fixer` clean.
- [ ] 6.4 ⛔ attend:auteur — Integration suite against a real Temporal server. **Run, and red for
      reasons that predate this change.** A live server was available (`temporal server start-dev`,
      `127.0.0.1:7233`, namespace `default`); the suite was run from the primary copy, whose
      `symfony/vendor/gplanchat/*` are symlinks onto `src/`, so it did test this tree:
      `Tests: 13, Assertions: 9, Errors: 8, Skipped: 1`. None of the eight touch the query
      registry — see §7. The tick belongs to whoever repairs those tests, not to this change.

## 7. Found while running 6.4 — three defects that are not this change's

- [ ] 7.1 ⛔ attend:auteur — `Gplanchat\Bridge\Temporal\TemporalStartingEventStore` **exists
      nowhere in the repository**: not in `src/`, not on any remote branch, not anywhere in the
      history (`git log --all -S`). `TemporalJournalEventStoreIntegrationTest` and
      `TemporalInterpreterMirrorIntegrationTest` both construct it, so seven tests die at
      `Error: Class ... not found`. The only copy on this machine is in an untracked scratch
      worktree. Decide whether those two tests describe work that was never merged, or work that was
      renamed — `TemporalJournalEventStore` is the only event store the bridge has today.
- [ ] 7.2 `WorkflowServiceExecutionRpcIntegrationTest:78` passes `TemporalConnection::$namespace` —
      a `WorkflowNamespace` value object — to protobuf's `setNamespace()`, which wants a string:
      `InvalidArgumentException: Expect string`. A stale call site left by the value object's
      introduction.
- [ ] 7.3 The CI job **"Tests d'intégration Temporal (gRPC + Temporal auto-setup)" is green while
      testing nothing**: `Tests: 13, Assertions: 3, Skipped: 10` in **45 ms** — every server-touching
      test skips at `setUpBeforeClass` before a single RPC. The gate has reported success through
      7.1 and 7.2 for as long as they have existed. Find why the socket check fails there — the
      compose file does publish `7233` — or the suite will keep passing by not running.
