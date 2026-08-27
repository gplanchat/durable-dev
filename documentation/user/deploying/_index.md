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

### Or declare a change point

When the change fits in a branch, say so in the workflow and let each execution keep the behaviour
it started on:

```php
use Gplanchat\Durable\Versioning\ChangePoint;

$version = $this->environment->version('add-discount', ChangePoint::DEFAULT_VERSION, 1);

if (ChangePoint::DEFAULT_VERSION === $version) {
    $total = $this->await($this->billing->totalWithoutDiscount($cart));   // runs already in flight
} else {
    $total = $this->await($this->billing->totalWithDiscount($cart));      // everything from now on
}
```

The answer is **fixed the first time an execution reaches that point** and read back from its
journal afterwards. Deploy what you like next: a run already past the point keeps its behaviour.

Three things worth knowing before you use it:

- **The change id lives in the journal.** Renaming it later makes every in-flight execution look
  like it never reached the point. Pick a name you can live with.
- **A run that passed this place before the point existed gets `DEFAULT_VERSION`** — it started on
  the old behaviour, it finishes on the old behaviour. Nothing is written for it; it is recognised,
  not marked.
- **The divergence check still applies everywhere else.** Declaring one change point does not
  license an undeclared change three lines below: that one still stops the run.

### Deleting the old branch

The branch may go once no live execution can still resolve to it. On the **Temporal backend** the
server answers that, because each marker is accompanied by a standard search attribute:

```
temporal workflow list --query 'TemporalChangeVersion = "add-discount-1"'
```

An empty answer means nobody is on version 1 any more, and the `DEFAULT_VERSION` branch can go.

**On the In-Memory and DBAL backends there is no search attribute and no equivalent answer.** There,
knowing when a branch is dead means knowing your own executions — which in practice means keeping
the branch until you are certain, or using the workflow-type rename below, whose drain window is
visible.

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

This costs two classes and a drain window. It remains the right answer when the change is **too
large to express as a branch** — a different set of activities, a different shape entirely — where a
change point would only make one workflow carry two workflows.

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

The decisions behind all of this: [DUR042](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR042-replay-divergence-guard.md)
for why the check rests only on what the journal already records, and
[DUR044](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR044-declared-change-points.md)
for change points — including why an execution older than the point is recognised rather than
marked, and why the search attribute is part of the primitive rather than an extra.
