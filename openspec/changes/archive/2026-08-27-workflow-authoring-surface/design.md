# Design

## What was probed, and what was assumed

Per the house rule, the boundary between observed and assumed.

**Observed, by counting callers across `src/`, `tests/`, `documentation/user/` and `symfony/src/`:**

| Method | moteur | tests | docs | exemple |
|---|---|---|---|---|
| `activity()` | 1 | 47 | 4 | 1 |
| `activityStub()` | 0 | 0 | 7 | 25 |
| `registerQueryHandler()` | 1 | 1 | 0 | 0 |
| `hasQueryHandler()` / `callQueryHandler()` | 1 each | 0 | 0 | 0 |
| `async()` | 0 | 0 | 0 | 0 |

The one engine call to `activity()` is `ActivityStub::__call()`. The sample application has already
moved to the stub — twenty-five of its twenty-seven scheduling calls. **The old form survives in the
test suite, not in the product.**

**Observed, by reading:** `WorkflowTestEnvironment::run()` accepts `callable`. There is no
class-based entry point. `ActivityStub::__construct()` requires an `ActivityContractResolver`, so a
stub cannot be built without a contract carrying `#[ActivityMethod]`.

**Since observed — task 1.1.** The assumption was that no third-party code calls `activity()`
directly. It holds, and it was worth checking: the packages are published at `v0.1.0-alpha5`, so
this was the difference between a rename and a break.

GitHub code search finds exactly one repository declaring `gplanchat/durable` in its
`composer.json`: `kiboko-labs/quovadis-gdpr-lifecycle`, private, last pushed 2026-08-25. Its usage:

| symbol | occurrences |
|---|---|
| `->activity(` | **0** |
| `activityStub` | 5 |
| `WorkflowEnvironment` | 7 |
| `registerQueryHandler` | 0 |

The only external consumer already schedules exclusively through the typed stub. Inside the
repository, one sample workflow calls `activity()` directly —
`symfony/src/Samples/Workflow/Periodic/PeriodicGreetingWorkflow.php` — and one test registers a
query handler, in `WorkflowTaskProcessorTest`. Everything else is the forty-six calls in the suite.

**Verdict for task 1.2:** a single breaking release, no deprecation window. There is nothing in the
wild to deprecate for, and a deprecated method shorter than its replacement stays in use.

Search coverage is what it is: GitHub code search sees public repositories and those the token can
read. A consumer in a private repository outside this account would not appear. Given
`0.1.0-alpha`, that residual risk is accepted rather than mitigated.

**Nothing was probed against a Temporal server**, and nothing here needs to be: no wire format, no
command, no history rule changes. This is a change to what PHP code can reach.

## Why the primitive cannot simply be deleted

`ActivityStub::__call()` ends with `$this->environment->activity($activityName, $payload, $options)`.
The stub is a proxy over the very method being hidden.

Two routes, and the choice matters more than it looks:

1. **A narrower port.** The stub receives, at construction, something that schedules — not the whole
   environment. The environment keeps the method but marks it internal; the stub no longer needs the
   public surface at all. Small, and it makes the dependency honest: a stub needs to schedule, not
   to sleep, race or continue-as-new.
2. **Package-private by convention.** PHP has no such thing; the method would stay public with an
   `@internal` docblock, and static analysis would have to enforce it. This is what "not part of the
   surface" would otherwise mean, and it enforces nothing at runtime.

Option 1 is the one this change takes. It costs one interface and makes `ActivityStub`'s
constructor state what it actually needs.

## Why the test harness has to change first

The forty-seven direct calls in the suite are not sloppiness. `run(callable $handler)` is the only
entry point, so a test workflow is a closure receiving the environment, and inside a closure there
is no constructor to build a stub in. The old form is the only form available.

Give the harness a class-based run and those tests can take the production shape. Leave it, and
hiding `activity()` would break the suite with no replacement — which is why this ordering is not
negotiable, and why the tasks put the harness before the removal.

The closure form stays. A test that wants an anonymous three-line workflow should not have to
declare a class and a contract for it. What changes is that the guide stops calling it "the same
signature as your real workflow", which it has not been since the environment moved to the
constructor.

## Why queries are different from the rest

`registerQueryHandler()` is called once, by the definition loader, while building a workflow
instance. `hasQueryHandler()` and `callQueryHandler()` are called by the Temporal task processor
when a query arrives from the server. None of the three is reachable from a well-formed workflow:
an author declares `#[QueryMethod]` and the engine does the rest.

They are on `WorkflowEnvironment` because that is the object the engine already had in hand, not
because a workflow needs them. Moving them behind an interface the engine holds — and the workflow
does not — costs nothing at the call sites and removes three ways to get a query wrong.

## Rejected

- **Deprecating rather than removing.** A deprecated method that is shorter than its replacement
  stays in use. The library is at `0.1.0-alpha`, which is when a surface can still be corrected
  cheaply.
- **Removing `continueAsNew()` and `executionId()` because nothing calls them.** No caller is not
  the same as no purpose. Both are things a workflow may legitimately want; their absence from the
  suite is a gap in coverage, not evidence.
- **Hiding the child-workflow verbs in the same change.** The same argument applies to them, and
  they should follow. Doing both at once would put two independent breaks in one review.
