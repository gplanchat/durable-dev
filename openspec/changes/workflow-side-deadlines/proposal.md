## Why

Bounding a wait in time is possible today only by composing a race by hand:

```php
$winner = $env->any(
    $activities->callProvider($orderId),
    $env->timer(Duration::seconds(30)),
);
```

This is the pattern the user documentation teaches (`documentation/user/workflows/_index.md`). It
is not merely verbose — **it is ambiguous**. `any()` returns the winning value and nothing else, and
a timer resolves to the value its inner awaitable carries. The moment the awaited work can
legitimately return `null` or `false`, the workflow cannot tell "the provider answered nothing"
from "the deadline elapsed". A saga that compensates on timeout compensates on a legitimate empty
answer too.

Three gaps follow from the same missing concept:

- there is no way to ask "did the deadline win?", because branch identity is lost at the boundary
  of `any()`;
- `waitSignal()` takes no deadline at all, although "wait for approval, give up after an hour" is
  the canonical saga shape;
- `src/Durable/Exception/` carries no timeout type, so nothing can be caught by intent.

A fourth gap is not visible from the call site and is the reason this is a change rather than a
helper: a signal wait consumes a positional slot. If the deadline elapses and the signal arrives
afterwards, a later replay finds that signal for the same slot and resolves it — reaching the
opposite verdict from the original execution. **A hand-written race over a signal wait is not
replay-safe today.**

## What Changes

- A workflow SHALL be able to await any awaitable under a deadline, and SHALL be able to
  distinguish an elapsed deadline from any value the awaited work could have returned.
- An elapsed deadline SHALL surface as a typed timeout failure, catchable on its own.
- Losing branches SHALL be cancelled: no orphaned activity, no dead timer left to wake the
  execution.
- `waitSignal()` SHALL accept an optional deadline, with the same failure and the same guarantees.
- A deadline verdict SHALL be stable across replay, **including when the awaited event arrives
  after the deadline elapsed**.
- Both backends SHALL reach the same verdict; nothing here is backend-specific.
- **BREAKING** none. Deadlines are opt-in, and the existing `any()` + `timer()` composition keeps
  working exactly as before.

## Capabilities

### New Capabilities

- `workflow-deadlines`: bounding a wait in time from workflow code — the verdict, its stability
  across replay, cancellation of the losing branch, and the deadline on a signal wait.

### Modified Capabilities

<!-- None: no existing documented requirement changes. -->

## Impact

- **Domain** (`src/Durable`): a deadline-aware await on `WorkflowEnvironment`, an optional deadline
  on `waitSignal()`, one new failure exception, and the history rule that makes a signal verdict
  stable.
- **Backends**: none specific. The deadline composes primitives both backends already implement;
  the parity tests exist to prove it.
- **User documentation**: the race-with-timer example becomes the composition primitive it should
  always have been, and stops being taught as the way to express a timeout.
- **ADR**: DUR032 records why a timeout is a failure rather than a null, and why a signal deadline
  needs a journaled verdict.
- **Dependencies**: none.
