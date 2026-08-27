## Why

DUR003 says the runner already does this:

> `WorkflowTaskRunner` enforces determinism by: […] 2. Comparing the awaitable type and identity at
> each suspension point against the history record for that slot — 3. Raising a non-determinism
> error if a mismatch is detected

Neither point is implemented. `src/Bridge/Temporal/Worker/WorkflowTaskRunner.php` is 150 lines and
contains no comparison and no such error. What exists is a **positional** lookup, five independent
counters deep:

| Call site in `ExecutionContext` | Counter | Lookup |
|---|---|---|
| `activity()` | `activitySlotIndex` | `findActivitySlotResult(int $slot)` |
| `nexusOperation()` | `nexusOperationSlotIndex` | `findNexusOperationSlotResult(int $slot)` |
| `childWorkflow()` | `childWorkflowSlotIndex` | `findChildWorkflowForSlot(int $slot)` |
| `delay()` / `timer()` | `timerSlotIndex` | `findTimerSlotResult(int $slot)` |
| `sideEffect()` | `sideEffectSlotIndex` | `findSideEffectForSlot(int $slot)` |

`activity(string $name, …)` takes the name and never compares it to what history recorded at that
index. Neither does `nexusOperation()` with its endpoint, service and operation. The whole
`WorkflowHistorySourceInterface` is keyed to an integer, documented as "first-occurrence order".

The consequence is not a missing feature, it is a **silent one**. Insert an activity call ahead of
an existing one, deploy while runs are in flight, and replay resolves the new call with the old
call's recorded result. No error is raised. Wrong data enters the workflow and is journaled as if
it had always been there.

This is the failure mode this codebase treats as the most expensive — DUR036 refused a silent
fallback for exactly this reason and made the DBAL backend refuse Nexus out loud instead. The same
argument applies here, and here it is not a design choice: it is a guard the ADR already claims.

Workflow versioning is the change that comes after this one, and it needs this one first: a
versioning API is the **sanctioned exception** to the guard. Without a guard there is nothing for
it to except.

## What Changes

- Replay SHALL compare the identity of what a workflow schedules against the identity recorded at
  that slot, for every kind of slot, and SHALL fail the workflow task when they differ.
- The failure SHALL name the slot, what history holds and what the code asked for. A divergence
  that is merely reported as "non-deterministic" costs the reader the diff they need.
- The comparison SHALL rest on identity already present in history — the activity name, the Nexus
  triple, the child workflow type — and SHALL NOT introduce a new event field. If a slot kind holds
  no such identity today, that gap is stated rather than filled by widening the journal.
- DUR003 SHALL be brought into agreement with the code, whichever way the work lands.
- **BREAKING** no for correct workflows: a run whose code has not changed produces the same
  comparison result at every slot. A run whose code has changed stops resolving wrong values and
  starts failing instead — which is the point, and which will surface existing latent breakage.

### Not in scope

- **A versioning or patching API.** Its own change, and it depends on this one.
- **Widening the journal** so that slot kinds lacking recorded identity can be checked. Whether any
  do is task 1; filling a gap is a separate decision from checking what is already there.
- **`sideEffect()`.** It records a value precisely because the closure is not reproducible; there is
  no identity to compare and inventing one would defeat the primitive.

## Capabilities

### New Capabilities

- `workflow-replay-integrity`: replay refuses to resolve a slot with a record that does not belong
  to it.

### Modified Capabilities

<!-- None: no existing capability claims this today. That is the problem. -->

## Impact

- **Domain** (`src/Durable`): `WorkflowHistorySourceInterface` gains identity accessors per slot
  kind; `ExecutionContext` compares before resolving at four call sites.
- **Temporal bridge**: the divergence surfaces as a failed workflow task, not a failed workflow —
  the run stays resumable once the code is put back.
- **DBAL bridge**: same guard, same driver; the backend asymmetry does not apply here.
- **Test suite**: a replay test per slot kind, driven by changing the workflow class between two
  polls of the same history.
- **ADR**: a new DUR records what was actually built; DUR003's determinism section is corrected to
  match it.
- **Dependencies**: none.
