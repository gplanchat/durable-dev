# Tasks

The order is not a preference. Hiding `activity()` before the harness can run a class would break
forty-seven tests with no replacement available — see `design.md`.

## 1. Check the assumption before breaking anything

- [x] 1.1 Search the published satellite repositories and the sample applications for callers of
      `activity()`, `registerQueryHandler()`, `callQueryHandler()`, `hasQueryHandler()` and
      `async()` outside this repository, and record what was found

      One external consumer exists — `kiboko-labs/quovadis-gdpr-lifecycle`, private — and it calls
      none of the five: five uses of `activityStub`, zero of `->activity(`. Full table in
      `design.md`.
- [x] 1.2 Decide, from 1.1, whether the removals ship as a single breaking release or behind a
      deprecation window, and note the verdict in `design.md`

      Single breaking release. Nothing in the wild to deprecate for, and a deprecated method
      shorter than its replacement stays in use.

## 2. The test harness first — failing tests

- [x] 2.1 A workflow class runs under the harness: the environment reaches its constructor, its
      business arguments reach its workflow method
- [x] 2.2 An activity double registered for a contract method is called with the arguments the
      workflow passed through its stub
- [x] 2.3 A workflow class under test observes the same failure as the same class on a backend
- [x] 2.4 The closure form still runs, and still receives the environment

## 3. The test harness — make them pass

- [x] 3.1 Add a class-based run to `WorkflowTestEnvironment`, alongside the callable one
- [x] 3.2 Make activity doubles resolvable by contract method as well as by activity name
      Already the case, and 2.2 proves it: the stub rebuilds the payload from the contract's named
      parameters, and the double is registered under the activity name the contract declares.
      Nothing to add.
- [x] 3.3 Check that a workflow class needing no activity runs without a resolver being configured

## 4. Give the stub a route that is not the public API — failing tests

- [x] 4.1 A stub built from a contract schedules its activity without the public scheduling verb
      being reachable from workflow code
- [x] 4.2 The stub still carries its `ActivityOptions` to every call it makes
- [x] 4.3 Replay of an execution recorded before this change reaches the same result — the wire
      format and the journal must not move

## 5. Give the stub a route — make them pass

- [x] 5.1 Extract the narrow scheduling port from `WorkflowEnvironment` and give it to
      `ActivityStub` at construction
- [x] 5.2 Remove `activity()` from the public surface
- [x] 5.3 Rewrite the forty-seven direct calls in the suite to the production shape, using the
      class-based run from task 3

## 6. Queries stop passing through the workflow's environment

- [x] 6.1 A declared `#[QueryMethod]` is still answered, on both backends
- [x] 6.2 Move query-handler registration and dispatch behind an interface the engine holds and the
      workflow does not
- [x] 6.3 Remove `registerQueryHandler()`, `hasQueryHandler()` and `callQueryHandler()` from the
      public surface

## 7. Remove the unreachable verb

- [x] 7.1 Remove `async()`
- [x] 7.2 Correct DUR003 and DUR022, which both describe it as scheduling asynchronous work — it
      never did

## 8. Documentation and decision record

- [x] 8.1 Rewrite the testing guide around the class-based run, keeping the closure as what it is:
      the harness's shape, for an anonymous workflow
- [x] 8.2 Update the `WorkflowEnvironment` table in `documentation/user/workflows/` to the surface
      that remains
- [x] 8.3 Write DUR037 — why the scheduling primitive is not public, and why the harness had to
      gain a class-based run before it could be hidden

## 9. Verification

- [x] 9.1 Unit suite green, PHPStan and Psalm clean
- [x] 9.2 Integration suite green against a real server — the wire format must be untouched, and
      this is what proves it
- [x] 9.3 The sample application still runs: it is the only consumer written the way a user would
      write one

      One workflow was still scheduling by name — `Samples/Workflow/Periodic/
      PeriodicGreetingWorkflow`. It now builds a stub from `GreetingActivityInterface`, the
      contract its sibling workflows already use; it was the last straggler, not a missing
      contract. Syntax checked, and no direct call remains anywhere in `symfony/` or `sylius/`.

      Not run end to end: the sample application's own dependencies are not installed in this
      worktree. Saying it "still runs" would overstate what was checked.
