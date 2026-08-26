# DUR033 — Assemblers return an Awaitable, and `await()` is the only wait

## Status

Accepted

## Context

`WorkflowEnvironment` carried four names for two behaviours. `race()` was `return $this->any(...)`
word for word; `all()` was `return $this->parallel(...)` word for word. And `parallel()` was not
parallel: it looped `await()` over its arguments in declaration order. It produced the right
result only because `activity()` emits its schedule command at construction, so every branch was
already in flight before the first `await()` blocked — an accident of eager scheduling, not a
property the name claimed.

More consequential than the duplication: all four **awaited on the caller's behalf and returned
values**, so none of them composed. An assembly could not be nested in another, and — the case
that matters — it could not be bounded. `await($env->any(...), Duration::seconds(30))` was
inexpressible, because `any()` had already returned a value by the time the deadline could be
named. DUR032 gave every wait a deadline, and an assembly was the one wait that could not have
one.

The direction of the correction was already recorded, in code that had never run.
`documentation/user/concepts/_index.md` documented

```php
[$a, $b] = $env->await($env->all([...]));
$winner  = $env->await($env->race([...]));
```

which is a `TypeError` twice over: `all()` returned an `array`, and it took variadics rather than
an array. The documented API was assemblers-returning-`Awaitable`; the implementation had drifted
from it. This ADR records the correction, not a new design.

Finally, the two behaviours on offer were the extremes. "Every member" and "the first member" were
expressible; "three price providers out of eight are enough to decide" was not, although it is the
shape that makes a fan-out worth doing.

## Decision

### `await()` is the only method that waits

Every other method assembles and returns immediately: `activity()`, `timer()`,
`scheduleChildWorkflow()`, and the three assemblers below all return an `Awaitable`. A method that
waited on the caller's behalf could not be composed with anything, which is the only reason to
write an assembler in the first place.

`race()` and `parallel()` are **deleted** rather than converted. They were aliases; converting
them would have preserved four names for what is now three distinct behaviours.

### Three assemblers, named by how many members must finish

```php
$env->all($a, $b, $c)      // Awaitable of [$a, $b, $c] — every member, in declaration order
$env->any($a, $b, $c)      // Awaitable of the first member to settle, whatever its fate
$env->some(2, $a, $b, $c)  // Awaitable of the first 2 members to succeed, keyed by position
```

`all()` and `some()` are one class, `QuorumAwaitable`: `all()` is the full quorum. `any()` stays
its own class, because a race resolves to the **value** of its winner while a quorum resolves to a
**collection** — the same class cannot honestly return both.

The signature is variadic. The documentation's array form was the outlier; every real call site
passed variadics.

### `some()` counts members that succeed, never members that failed

A quorum exists to survive members that fall over. Counting a failure towards the count would make
`some(3, ...)` strictly worse than `all()`: three failures would settle the wait by raising the
first of them, discarding results already in hand. So only fulfilled members count.

The corollary is not optional. Once too few members remain in the race to reach the count, the
wait is settled by the first failure — without that rule an unreachable quorum would suspend the
execution forever.

`all()` keeps the same definition without a special case: when the count equals the number of
members, "enough succeeded" and "all settled without failure" are the same statement.

### The result is keyed by declaration position

`some(3, ...$eight)` returns three values keyed `0..7`, so the caller knows **which** members
answered. Identity comes for free where a partial result makes it necessary. `all()` is unaffected
in practice: every position is present, in order, so `[$a, $b] = …` still destructures.

This is a narrow answer to DUR032's non-goal, not a reversal of it. `any()` still returns a value
and still drops branch identity; only a partial quorum needed to say which members it collected.

### An unreachable quorum is refused where it is written

`QuorumAwaitable` rejects a count below one or above the number of members, and `any()` rejects an
empty argument list. Both would otherwise produce a wait that never settles — an execution
suspended with nothing in the logs, which this codebase already treats as the failure mode worth
paying to prevent (see `TaskQueue`).

### Composites declare themselves, and there is one cancellation walk

`CompositeAwaitable` exposes `members()`. Two places in the engine traverse an assembly rather than
inspecting it from outside: `AwaitableInspector::waitsOnTimer()`, which decides whether a wake is
scheduled, and the cancellation of branches that no longer have a purpose. Both had a chain of
`instanceof` over the known composites, so adding one was enough for its members to stop being
seen — an execution with no wake, or an orphaned activity.

The cancellation walk itself existed **twice**, at two different depths: the workflow-cancellation
path recursed into composites, the race-loser path stopped at the first level. That was
unreachable while `any()` returned a value, and became the normal case the moment
`await($env->all($a, $b), $deadline)` was expressible — the deadline would fire and both
activities would keep running. `AwaitableCancellation::cancelUnsettled()` is now the single walk,
called from both.

`CancellingAnyAwaitable` is renamed `CancellingCompositeAwaitable`: it wraps a quorum as well as a
race. Its `$tracked` constructor argument is dropped, having always been the inner composite's own
members.

### `then()` leaves the awaitable contract

`Awaitable` declared `then(?callable, ?callable): void`. It returned `void`, so it never chained;
`otherwise()` never existed, its role being the second argument. Its only callers in the whole
repository were the six implementations relaying it to one another.

It is removed, and with it `Deferred`'s callback list and `notify()`. A callback is not the right
composition tool here for a reason that is not taste: **it is not journaled**. On replay the
awaitable settles from history and the callback fires again, so any side effect living in one runs
on every replay — the determinism trap this engine exists to prevent. Two lesser reasons point the
same way: `Deferred::notify()` isolated callback failures into `error_log`, so an `otherwise()`
built on it would fail silently; and on a composite, `then()` registered on every member, firing
once per settling member rather than once for the assembly.

Composition is therefore in two steps, in the order the journal replays them: the assemblers
assemble, `await()` waits, and `otherwise()` is the `catch` around it.

## Consequences

- **BREAKING.** `all()` and `any()` return an `Awaitable` instead of a value: every call site wraps
  in `await()`. `race()` and `parallel()` are gone — `race()` becomes `any()`, `parallel()` becomes
  `all()`, both inside an `await()`.
- **BREAKING.** `Awaitable::then()` is removed. A third-party implementation of the interface has
  one fewer method to write; a caller that registered a callback has none, none having existed.
- `CancellingAnyAwaitable` is renamed and its constructor takes two arguments instead of three;
  `innerAny()` becomes `inner()`.
- `all()` goes from N fiber suspensions to one, so an assembly costs fewer workflow tasks. The
  **command sequence is unchanged** — branches are scheduled at construction, not at await — but
  the task boundaries are not, and only the Temporal command-buffer tests cover that at unit level.
- `QuorumAwaitable::isSettled()` unwraps its settled members to tell success from failure, and
  `isSettled()` is polled in a loop. A cancelling composite nested inside a quorum therefore has
  its loser-cancellation invoked once per poll. This is safe because `cancelScheduledActivity()`
  and `cancelScheduledTimer()` remove the entry from the pending map before emitting, so a second
  call is a no-op — a property now pinned by a test rather than left to be rediscovered.
- The user documentation's composition example runs for the first time.

## Alternatives considered

- **Keeping the eager assemblers and adding composable twins.** Four names for two behaviours would
  have become eight names for three. The eager form is one `await()` away in every case.
- **`QuorumAwaitable extends AnyAwaitable`.** It would have left the traversal code untouched, at
  the price of a subclass that redefines `isSettled()` — the exact method the runtime polls — to
  mean something else. Reuse by inheritance across differing semantics, on the hottest predicate
  of the engine.
- **`some()` counting settled members rather than successful ones.** Consistent with `all()` on the
  surface, incoherent underneath: `getResult()` would have to either raise despite holding enough
  good results, or return fewer entries than the count promised.
- **The documentation's array signature, `all([...])`.** Rejected in favour of the variadic form
  the code and every call site already used; `some()` needs a leading count either way.

## Related decisions

- **DUR003** — fiber-based replay and awaitables.
- **DUR022** — `WorkflowEnvironment` as the only workflow-side API.
- **DUR032** — workflow-side deadlines; the reason an assembly had to become composable.
- **DUR027** — fiber replay and workflow task boundaries.
