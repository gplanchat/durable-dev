# Design

## The work existed already, and was lost to a merge

`aa89793` implemented this change in full: the registry on `ExecutionContext`, the extra argument
through the fiber driver, `WorkflowTaskResult` carrying the registry, the three methods removed,
and the one closure-registered bridge test rewritten as a class. It passed 376 unit tests and
**twenty integration tests against a real Temporal server**.

It is an ancestor of `main` and its file is not: the merge that brought `workflow-authoring-surface`
and `workflow-conditions-and-handler-dispatch` together resolved the query conflict in favour of
`main`. That resolution was about untangling two concurrent branches, not a verdict on the design —
which is exactly what DUR039 then recorded as "it gets its own change".

This change restores `aa89793` rather than rewriting it. Six files conflicted; the resolutions are
listed below.

## What was probed, and what was assumed

**Assumed, not probed.** No Temporal server was queried for this change, and none needed to be: the
wire format does not move. The commands, the query results and the payload encoding are byte-for-byte
what they were — only the PHP object the processor reads the handlers from changes.

The claim that rests on a server is `aa89793`'s twenty green integration tests, and that run was
against a **different `main`**. It is evidence about the wire format, which has not moved, and not
about this tree. The integration suite has not been replayed here. Anyone landing this should say so
or run it:

```
temporal server start-dev --namespace durable-test --port 7233
DURABLE_TEMPORAL_ADDRESS=127.0.0.1:7233 vendor/bin/phpunit --testsuite integration
```

## Why the registry travels as a second argument

`WorkflowFiberDriver` calls the workflow handler. The handler is a callable, and the vast majority
of the ones in the suite are closures that declare exactly one parameter, the environment.

**PHP accepts extra arguments to a userland function.** So:

```php
$fiber = new \Fiber(static fn() => $handler($environment, $queries));
```

leaves every one-parameter closure working, unmodified. Only `WorkflowDefinitionLoader`'s factory
declares the second parameter, and it defaults it to null so that a handler invoked from elsewhere
still builds a registry of its own.

The alternative — threading the registry through every construction site — would have touched the
whole suite to deliver a value only one caller reads.

## Why signals and updates keep their imperative verb

This is the question the change turns on, and the answer is not symmetry but **place**.

| | registered by | dispatched by | when |
|---|---|---|---|
| signal / update | `WorkflowEnvironment::onSignal()` / `onUpdate()` | `WorkflowEnvironment::dispatch()`, private | inside `await()`, in the fiber |
| query | the definition loader, into the registry | `WorkflowTaskProcessor` | between two workflow tasks, outside the fiber |

For a signal, the object that registers is the object that dispatches; the registration verb is on
the object that uses it. For a query, the environment was a place to park handlers on the way to the
worker — which is why an author could reach them at all.

DUR035 argued the imperative form is load-bearing because nearly every workflow in the suite is a
closure and a closure cannot carry an attribute. That argument survives intact for signals and
updates. It does not survive for queries, because `aa89793` measured it: the single test that
registered a query from a closure became a class, and nothing else in the suite needed the verb.

A test now pins the asymmetry
(`ActivitySchedulingPortTest::testSignalAndUpdateRegistrationStayOnTheSurface`) so a later reader
finds a decision and not an oversight.

## The cost, stated

**A workflow expressed as a closure can no longer answer a query.** A closure cannot carry an
attribute and the imperative door is now shut. A workflow that answers queries is a class.

This is a real reduction, not a no-op, and the alternative that would have avoided it — keeping a
renamed `onQuery()` on the environment — was considered and rejected: it keeps a verb because its
neighbours have one, for a use case the measurement says nobody has.

## Conflict resolutions

Six files conflicted when restoring `aa89793` onto today's `main`.

| File | Resolution |
|---|---|
| `WorkflowEnvironment` | Query handler map removed; the signal and update maps that landed since are kept. The `onSignal()` docblock cited `registerQueryHandler()` as its peer — rewritten to state the place distinction instead. |
| `WorkflowDefinitionLoader` | Query registration goes to the registry; signal and update registration stays on the environment. |
| `WorkflowTaskResult` | Carries the registry **and** the protocol-messages field the conditions work added since. |
| `WorkflowTaskRunner` | Passes `$context->queryHandlers()`; the update reply logic added since is untouched. |
| `WorkflowTaskProcessorTest` | Takes `aa89793`'s class-based form. Its `QueryableWorkflow` fixture called `waitSignal()`, a method the conditions work removed — it suspends on a condition nothing satisfies, as the closure it replaces did. |
| `ActivitySchedulingPortTest` | Takes `aa89793`'s test, plus a new one pinning that `onSignal()`/`onUpdate()` stay. |

## Behaviour that must not drift

At the processor, "no handler registered" and "handler threw" both yield
`QUERY_RESULT_TYPE_FAILED`. Collapsing the two-step `has()` / `call()` into one call would be
tempting and would risk turning an unknown query into something else. Both branches are kept
distinct and both still set FAILED.

The query path journals nothing. Updates call `recordUpdateHandled`; a query answers and records
no fact. Nothing in this change adds a `record*` to that path.

Neither drift is visible to a unit test — both surface only against a real server, which is the
slowest feedback available here. That is why they are written down.
