---
title: Changing a running workflow
weight: 28
---

# Changing a workflow that is already running

A workflow that runs for weeks outlives the deployment that started it. Sooner or later you will
deploy a change while executions are still in flight, and what happens then is worth knowing before
it happens rather than after.

## Why a running execution is not just old code

A workflow does not resume where it stopped: it **replays from the start** on every task, and each
step it schedules is matched to the journal **by position**. Step 3 is the third activity this
workflow scheduled — not the third call to that particular activity.

So inserting one call ahead of another shifts everything after it. Position 3 in the code no longer
means what position 3 in the journal means.

```php
// The code that started the run
$this->await($this->activities->chargeCard($order));   // position 0
$this->await($this->activities->shipOrder($order));    // position 1

// The code you just deployed
$this->await($this->activities->reserveStock($order)); // position 0  ← inserted
$this->await($this->activities->chargeCard($order));   // position 1
$this->await($this->activities->shipOrder($order));    // position 2
```

The run that is in flight recorded `chargeCard` at position 0. The new code asks for
`reserveStock` there.

## What you will see

The execution stops on that task, and the failure names both sides:

```
Replay divergence at activity slot 0 of execution "order-8f21c3":
history recorded "chargeCard", code scheduled "reserveStock".
This history was written by a different version of the workflow.
```

On the Temporal backend this fails the **workflow task**, not the execution. The run stays alive,
its history is untouched, and the server retries the task. In the Temporal UI it shows as a workflow
task failure, and the execution is still `Running`.

Every step that carries an identity is checked this way: activities by name, Nexus operations by
their endpoint/service/operation triple, child workflows by type.

## What to do about it

### Revert, and the run finishes

The failure is telling you the deployment does not fit the runs it landed on. Put the previous
version back, and the next retry replays cleanly — the run continues exactly where it was, having
lost nothing but the time between the two deployments.

This is the whole reason the task fails rather than the run.

### Or give the new shape a new name

When you cannot wait for runs to drain, register the changed workflow under a **new type name** and
keep the old class registered until the old executions finish:

```php
#[Workflow('checkout')]      // keep, until the last old run ends
final class CheckoutWorkflow { … }

#[Workflow('checkout-v2')]   // new starts go here
final class CheckoutV2Workflow { … }
```

Executions resolve their handler by the type recorded when they started, so runs already in flight
never see the new class. New runs start on `checkout-v2`.

This costs two classes and a drain window, and it is currently the only way to change a workflow
without waiting. A per-change-point versioning primitive is a separate piece of work.

## What is not checked

**Timers.** A timer records an absolute due date, not the delay that produced it, and its label is
optional — there is nothing in the journal that identifies *which* timer a position holds. Changing
only timer durations therefore replays without being reported.

The gap is narrower than it sounds: a shift escapes the check only if it touches timers **alone**.
As soon as an activity moves with it, the activity's name catches it.

## On the backends without workflow tasks

The In-Memory and DBAL backends have no notion of a workflow *task* — there is nothing to fail and
retry. There, a divergence ends the execution instead. That is still far better than the alternative
of resolving the wrong recorded value in silence, but reverting will not bring the run back.

---

The decision behind all of this, including why the check rests only on what the journal already
records, is [DUR042](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR042-replay-divergence-guard.md).
