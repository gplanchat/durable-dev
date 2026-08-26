---
title: Cancellation
weight: 27
---

# Cancellation

Cancelling an execution does not kill it. The cancellation is **raised inside the workflow, at the
point where it is waiting**, so the workflow can compensate before it ends — the equivalent of
Temporal's `CanceledFailure`.

---

## Compensating

```php
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;

final class CheckoutWorkflow
{
    public function __invoke(WorkflowEnvironment $env): string
    {
        try {
            return $env->await($env->activity('charge', ['orderId' => $this->orderId]));
        } catch (WorkflowCancelledFailure $e) {
            $env->await($env->activity('refund', ['orderId' => $this->orderId]));

            throw $e;   // the execution ends cancelled
        }
    }
}
```

Three outcomes, all legitimate:

| The workflow… | Outcome |
|---|---|
| rethrows the failure | the execution ends **cancelled** |
| swallows it and returns | the execution **completes** normally — a workflow may ignore cancellation |
| never awaits anything | the cancellation is never observed and the workflow completes |

The operation being awaited is cancelled at the same time. In a race, every pending branch is.

---

## Delivered exactly once

The cancellation is raised **once per execution**. Without that bound, the very awaits used to
compensate would be cancelled in turn and the compensation would never run.

Determinism comes from the journal rather than from a marker: the pending operation is cancelled
with reason `workflow_cancelled`, and on replay that recorded outcome rejects the same awaitable at
the same place. The workflow therefore takes the same branch on every replay.

---

## Requesting cancellation

- **From a parent** — a child scheduled with `ParentClosePolicy::RequestCancel` is asked to cancel
  when the parent closes.
- **From outside, on Temporal** — `temporal workflow cancel`, or any client calling
  `RequestCancelWorkflowExecution`. The server records the request and reschedules a workflow task;
  the worker answers it.

---

## What it leaves in the journal

| Event | Meaning |
|---|---|
| `WorkflowCancellationRequested` | someone asked |
| `WorkflowExecutionCancelled` | the execution ended cancelled |
| `ActivityCancelled` / `TimerCancelled` with reason `workflow_cancelled` | the awaited operation was removed |

A race loser is cancelled with reason `race_superseded` instead, and surfaces as
`ActivitySupersededException` — a different situation that stays distinguishable.

---

## Race losers

```php
$winner = $env->any(
    $env->activity('slow-call', $payload),
    $env->timer(30.0),                   // an awaitable, like the activity above
);
```

When one branch wins, the others are cancelled: pending activities are removed from the queue and
pending timers stop waking the execution.

`timer()` returns an `Awaitable`, exactly like `activity()` — which is what makes the "activity with
a timeout" pattern above expressible. When you only want to wait, `sleep()` says so in its name and
awaits for you.
