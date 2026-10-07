---
title: Cancellation
weight: 27
---

# Cancellation

When you cancel an execution (one durable run of a workflow; see the [glossary](../glossary/)),
Durable does not kill it. The cancellation is **raised inside the workflow, at the point where it is
waiting**, so the workflow can compensate before it ends. It is the equivalent of Temporal's
`CanceledFailure`.

---

## Compensating

To undo the steps that completed before a failure or a cancellation, register one compensation per
step with `Saga`, and run them from a `catch`:

```php
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Workflow\Saga;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'checkout')]
final class CheckoutWorkflow
{
    /** @param ActivityStub<OrderActivities> $orders */
    #[AsWorkflowMethod]
    public function run(
        string $orderId,
        #[Activities(OrderActivities::class)]
        ActivityStub $orders,
        WorkflowEnvironment $env,
    ): string {
        $saga = new Saga();

        try {
            $reservation = $env->await($orders->reserve($orderId));
            $saga->addCompensation(fn () => $env->await($orders->release($reservation)));

            $charge = $env->await($orders->charge($orderId));
            $saga->addCompensation(fn () => $env->await($orders->refund($charge)));

            return $env->await($orders->ship($orderId));
        } catch (DurableActivityFailedException|WorkflowCancelledFailure $e) {
            $saga->compensate();

            throw $e;   // the execution ends cancelled, or failed
        }
    }
}
```

`Saga` records one compensation per completed step and, on `compensate()`, runs them in reverse
order. Each compensation does its own `await()`, so it finishes before the next one starts. A
compensation that returns an `Awaitable` instead makes `compensate()` throw a `LogicException`. A
step that never completed has nothing to undo, because its compensation was never added. The first
compensation that throws stops the run, and its exception replaces the one being compensated.

The way the execution ends depends on what the workflow does with the exception. Each of these
outcomes is legitimate:

| The workflow… | Outcome |
|---|---|
| rethrows the failure | the execution ends **cancelled** |
| swallows it and returns | the execution **completes** normally; a workflow may ignore cancellation |
| never awaits anything | the cancellation is never observed and the workflow completes |

The operation being awaited is cancelled at the same time. In a race, every pending branch is
cancelled.

---

## Delivered exactly once

The cancellation is raised **once per execution**. If it were raised again, the awaits that the
compensation uses would be cancelled in turn, and the compensation would never run.

Replay (running the workflow code again from its first line, with each recorded step returning its
result) stays deterministic because the outcome is in the journal (the append-only history of what
the execution decided and received), with no separate marker. The pending operation
is cancelled with reason `workflow_cancelled`, and on replay that recorded outcome rejects the same
awaitable at the same place. The workflow therefore takes the same branch on every replay.

---

## Requesting cancellation

- **From a parent.** When the parent closes, a child scheduled with
  `ParentClosePolicy::RequestCancel` receives a cancellation request.
- **From outside, on Temporal.** Run `temporal workflow cancel`, or call
  `RequestCancelWorkflowExecution` from any client. The server records the request and reschedules a
  workflow task, which the worker then processes.
- **From your application, on Temporal.** Call `WorkflowClient::cancel($workflowId)` to request the
  cancellation, or `WorkflowClient::terminate($workflowId, $reason)` to end the execution at once
  without running more of its code. Both take the workflow id that `WorkflowClient::workflowId()`
  returns. On an execution that has ended, or that does not exist, the server answers NotFound, and
  both methods throw a `\RuntimeException` with code 5 that names the workflow id and keeps the server
  failure as previous. Nothing is changed. Terminating an execution twice therefore throws the second
  time. These methods exist on Temporal only. The journal backends (InMemory, DBAL, Illuminate,
  Magento) do not have them yet.
- **From outside, on the other backends.** The application has no entry point to request a
  cancellation on In-Memory, DBAL, Illuminate or Magento; the
  [capability matrix](../backends/#capability-matrix) lists the row as unsupported on the first
  three and "not yet" for the Magento Database column.

---

## What it leaves in the journal

| Event | Meaning |
|---|---|
| `WorkflowCancellationRequested` | a cancellation was requested |
| `WorkflowExecutionCancelled` | the execution ended cancelled |
| `ActivityCancelled` / `TimerCancelled` with reason `workflow_cancelled` | the awaited operation was removed |

A race loser is cancelled with reason `race_superseded` instead, and surfaces as
`ActivitySupersededException`. The two situations stay distinguishable.

---

## Race losers

```php
$winner = $this->environment->await(
    $this->environment->any(
        $this->quotes->callProvider($orderId),
        $this->quotes->callFallbackProvider($orderId),
    ),
    deadline: Duration::seconds(30),
);
```

When one branch wins, the others are cancelled. Their pending activities are removed from the
queue, and their pending timers no longer wake the execution. An elapsed deadline cancels them the
same way, and raises `DeadlineExceededException`.

**The time bound is the deadline on `await()`, not a third branch.** A timer racing the providers
would look like a winner. `any()` resolves to the winning *value* and nothing else, so a provider
that legitimately answers `null` becomes indistinguishable from thirty seconds of silence, and a
compensation path meant for the timeout runs on the empty answer too.

`timer()` does return an `Awaitable`, exactly like a stub call, so it *can* be a branch. Use it as
a branch when the timer is a real outcome, such as sending a nudge or taking the fallback path. Do
not use it as a deadline. When you only want to wait, call `sleep()`, which awaits for you.

[Creating a workflow](../workflows/#bounding-a-wait-in-time) shows the deadline in full, with what
the exception carries, `deadline()` and `awaited()`.
