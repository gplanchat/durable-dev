# Tasks

The order is not a preference. Hiding `activity()` before the harness can run a class would break
forty-seven tests with no replacement available — see `design.md`.

## 1. Check the assumption before breaking anything

- [ ] 1.1 Search the published satellite repositories and the sample applications for callers of
      `activity()`, `registerQueryHandler()`, `callQueryHandler()`, `hasQueryHandler()` and
      `async()` outside this repository, and record what was found
- [ ] 1.2 Decide, from 1.1, whether the removals ship as a single breaking release or behind a
      deprecation window, and note the verdict in `design.md`

## 2. The test harness first — failing tests

- [ ] 2.1 A workflow class runs under the harness: the environment reaches its constructor, its
      business arguments reach its workflow method
- [ ] 2.2 An activity double registered for a contract method is called with the arguments the
      workflow passed through its stub
- [ ] 2.3 A workflow class under test observes the same failure as the same class on a backend
- [ ] 2.4 The closure form still runs, and still receives the environment

## 3. The test harness — make them pass

- [ ] 3.1 Add a class-based run to `WorkflowTestEnvironment`, alongside the callable one
- [ ] 3.2 Make activity doubles resolvable by contract method as well as by activity name
- [ ] 3.3 Check that a workflow class needing no activity runs without a resolver being configured

## 4. Give the stub a route that is not the public API — failing tests

- [ ] 4.1 A stub built from a contract schedules its activity without the public scheduling verb
      being reachable from workflow code
- [ ] 4.2 The stub still carries its `ActivityOptions` to every call it makes
- [ ] 4.3 Replay of an execution recorded before this change reaches the same result — the wire
      format and the journal must not move

## 5. Give the stub a route — make them pass

- [ ] 5.1 Extract the narrow scheduling port from `WorkflowEnvironment` and give it to
      `ActivityStub` at construction
- [ ] 5.2 Remove `activity()` from the public surface
- [ ] 5.3 Rewrite the forty-seven direct calls in the suite to the production shape, using the
      class-based run from task 3

## 6. Queries stop passing through the workflow's environment

- [ ] 6.1 A declared `#[QueryMethod]` is still answered, on both backends
- [ ] 6.2 Move query-handler registration and dispatch behind an interface the engine holds and the
      workflow does not
- [ ] 6.3 Remove `registerQueryHandler()`, `hasQueryHandler()` and `callQueryHandler()` from the
      public surface

## 7. Remove the unreachable verb

- [ ] 7.1 Remove `async()`
- [ ] 7.2 Correct DUR003 and DUR022, which both describe it as scheduling asynchronous work — it
      never did

## 8. Documentation and decision record

- [ ] 8.1 Rewrite the testing guide around the class-based run, keeping the closure as what it is:
      the harness's shape, for an anonymous workflow
- [ ] 8.2 Update the `WorkflowEnvironment` table in `documentation/user/workflows/` to the surface
      that remains
- [ ] 8.3 Write DUR035 — why the scheduling primitive is not public, and why the harness had to
      gain a class-based run before it could be hidden

## 9. Verification

- [ ] 9.1 Unit suite green, PHPStan and Psalm clean
- [ ] 9.2 Integration suite green against a real server — the wire format must be untouched, and
      this is what proves it
- [ ] 9.3 The sample application still runs: it is the only consumer written the way a user would
      write one
