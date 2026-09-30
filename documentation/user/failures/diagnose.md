---
title: Diagnose a failed activity or workflow
weight: 10
---

# Diagnose a failed activity or workflow

Each section starts from what you observe, gives the cause, then what to do. An activity is a unit
of side effect in a workflow, such as an HTTP call, a database write or an e-mail. The journal is
the append-only record of everything an execution decided and received. See the
[glossary](../../glossary/).

## An activity stopped retrying before the attempts you expected

The final `ActivityFailed` event says why the retries ended, in its `retryState`:

```php
$failed->retryState();     // ActivityRetryState
$failed->isStalled();      // true when attempts were exhausted
```

| `retryState` | Cause | What to do |
|---|---|---|
| `NonRetryableFailure` | the exception is declared non-retryable | expected if you listed it; otherwise remove it from `nonRetryableExceptions` |
| `MaximumAttemptsReached` | every allowed attempt was used | check the limit: `RetryLimit::ofAttempts(3)` allows three executions in total, the first one included |
| `Timeout` | a schedule-to-start or schedule-to-close bound elapsed | check `scheduleToStart` and `scheduleToClose` in the activity's [`ActivityTimeouts`](../../options/#activitytimeouts) |
| `RetryPolicyNotSet` | no retry policy applied | check the retry options of the activity; see [`RetryLimit`](../../options/#retrylimit) |

A failure recorded with `InProgress` is not final: another attempt is expected. It appears when
retries are delegated to the Temporal server.

## An activity retries forever and its workflow never fails

No retry limit applies to the activity. Without an explicit limit, attempts are **unlimited**, so
an activity that always fails is retried forever and its workflow never reaches a failure.

Set a limit on the activity; see [Options](../../options/#retrylimit). For an exception that no
attempt can fix, also [stop retrying it](../stop-retrying/).

## `messenger:failed:retry` on a failed activity runs nothing

The message in `failure_transport` belongs to a **non-retryable** failure. Durable wrote that
attempt to the journal before throwing `UnrecoverableMessageHandlingException`. Running the message
again runs nothing, because the attempt is already in the journal.

The failure reaches the workflow as a failed activity. Handle it there, by catching it or by
compensating; see [Compensating](../../cancellation/#compensating).

## The log says *"dropped an early resume of execution"*

The full line, at `info` level on the `messenger` channel, reads *"dropped an early resume of
execution … the resume sent after its append carries the run"*.

The run is not lost, and nothing reaches `failure_transport`. A worker sends one resume before it
journals the fact the resume announces (an activity's outcome, a signal, a child's outcome, a fired
timer), and another one after. The early resume fails on purpose and is retried. After ten
redeliveries it is acknowledged and this line is logged. The second resume carries the run.

## A workflow ended with `WorkflowExecutionFailed`

The workflow code did not catch a failure. The `kind` field tells you where the failure came from,
for example `unhandled_activity_failure` for an activity failure the workflow let through, or
`workflow_handler_failure` when the workflow code itself threw. The full list is in
[Failure events and states](../events/#kind-of-workflowexecutionfailed).

On the Temporal backend, the error you read back is a typed `WorkflowExecutionFailed` with the name
of the failing activity, not a bare message.

## Related pages

- [Failure events and states](../events/)
- [Retries over Symfony Messenger](../messenger/)
