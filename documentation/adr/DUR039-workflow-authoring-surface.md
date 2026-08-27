# DUR039 — The workflow authoring surface

## Status

Accepted

## Context

`WorkflowEnvironment` is the single object a workflow author receives. It carried twenty public
methods. Counting their callers across the engine, the test suite, the user documentation and the
sample application produced three groups rather than one:

| Method | engine | tests | docs | sample |
|---|---|---|---|---|
| `activity()` | 1 | 96 | 4 | 1 |
| `activityStub()` | 0 | 0 | 7 | 25 |
| `async()` | 0 | 0 | 0 | 0 |

The sample application — the only consumer written the way a user would write one — had already
moved to the typed stub: twenty-five of its twenty-seven scheduling calls. The old form survived in
the **test suite**, not in the product. The one engine call to `activity()` was `ActivityStub`
itself, which scheduled by calling the very method the library had stopped teaching.

`async()` returned `Deferred::resolved($value)`. It took a value, not a callable, and scheduled
nothing. Two ADRs described it as scheduling asynchronous work.

## Decision

**A workflow schedules activities only through a typed stub.** Naming an activity as a string with
a free-form payload is not on the surface an author can reach. The primitive stays inside the
engine, behind `ActivitySchedulerInterface`, whose adapter `WorkflowEnvironment::activityStub()`
builds and never returns.

The port is deliberately **not implemented by `WorkflowEnvironment`**: doing so would make the verb
public under another name. It is held by an adapter over `ExecutionContext` — the engine-side
object a workflow never receives.

**`async()` is removed**, and the two ADRs that described it are corrected.

**The test harness gained a class-based run first.** `WorkflowTestEnvironment::run()` took a
callable, so a test workflow was a closure receiving the environment — a signature no real workflow
has had since the environment moved to the constructor. That, and not carelessness, is why
ninety-six direct `activity()` calls existed: inside a closure there is no constructor in which to
build a stub. `runWorkflowClass()` closed the gap, and the ordering was not negotiable — hiding the
primitive first would have broken the suite with no replacement to offer.

## Consequences

- **Breaking.** `activity()` and `async()` are gone. Each has a stated replacement, and a search of the published packages
  found one external consumer, which called none of them.
- A test now reads like production: same class, same constructor, same attributes. What you test is
  what you ship.
- The stub's dependency became honest: it needs to schedule, not to sleep, race or continue-as-new.
- The wire format did not move. Twenty integration tests against a real Temporal server say so, and
  no local assertion could have said it for them.

### Not decided here

The three query-plumbing methods — `registerQueryHandler()`, `hasQueryHandler()`,
`callQueryHandler()` — are the engine talking to itself, and the same argument applies to them.
They are **left in place**, because `workflow-conditions-and-handler-dispatch` landed on `main`
while this work was in progress and added imperative registration for signals and updates on the
same reasoning. Removing one half of that would settle a decision someone else had just
implemented, inside a review about something else. It gets its own change.

**Decided since, in DUR040.** The three are gone, and the signal and update verbs stay. The
distinction that settled it is one of place rather than principle: a signal is dispatched inside
`WorkflowEnvironment` during `await()`, a query is read by the worker outside the fiber.

## Alternatives considered

- **`@internal` and static analysis.** PHP has no package-private, so "not part of the surface"
  would have meant a docblock and a linter rule. It enforces nothing at runtime, and a method that
  is shorter than its replacement stays in use whatever the docblock says.
- **Deprecating rather than removing.** At `0.1.0-alpha`, with one external consumer that does not
  call any of them, a deprecation window would have bought nothing and kept the shorter form
  available.
- **Removing `continueAsNew()` and `executionId()` too**, since nothing calls them. No caller is
  not the same as no purpose: both are things a workflow may legitimately do. Their absence from
  the suite is a coverage gap, not evidence.
- **Hiding the child-workflow verbs in the same change.** The same argument applies to them and
  they should follow, but two independent breaks in one review is one too many.

## Related decisions

- **DUR022** — the workflow class contract and `WorkflowEnvironment`. Its API table listed
  `async()`, `resolve()` and `reject()`; all three were wrong, and are corrected.
- **DUR003** — replay and awaitables. Same correction for `async()`.
- **DUR004** — `ActivityStub`, activities and activity methods. The stub stops being one of two
  ways to schedule and becomes the only one.
- **DUR023** — activity authoring and the asynchronous invoker, which describes the static analysis
  that types stub calls. That analysis is now the only thing checking the scheduling surface, and
  therefore more load-bearing than before.
