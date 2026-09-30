---
title: Failure events and states
weight: 30
---

# Failure events and states

Reference for what Durable records when an activity or a workflow fails. An activity is a unit of
side effect in a workflow, such as an HTTP call, a database write or an e-mail. The journal is the
append-only record of everything an execution decided and received. See the
[glossary](../../glossary/).

One failed activity writes several events. Reading them tells you whether to compensate, raise an
alert or let the workflow fail.

## Journal events for one activity

| Event | Meaning |
|---|---|
| `ActivityScheduled` | the workflow asked for the activity |
| `ActivityTaskStarted` | one attempt began; one row per attempt |
| `ActivityTaskFailed` | **one attempt failed**, whether or not another attempt follows |
| `ActivityTaskCompleted` | only in journals written before #262; a success now writes `ActivityCompleted` alone |
| `ActivityCompleted` | final outcome: success |
| `ActivityFailed` | final outcome: failure |
| `ActivityCancelled` | final outcome: removed before completing |
| `ActivityCatastrophicFailure` | the failure itself could not be journaled safely |

`ActivityTaskFailed` is written for every failed attempt, including one that a later attempt
recovers from. Before this event existed, an attempt that failed and was followed by a success left
no trace in the journal.

## `retryState` of `ActivityFailed`

`ActivityFailed` carries a `retryState` that names which of four situations ended the retries.
Earlier versions recorded all four the same way.

```php
use Gplanchat\Durable\Failure\ActivityRetryState;

$failed->retryState();     // ActivityRetryState
$failed->isStalled();      // true when attempts were exhausted
```

| State | Meaning |
|---|---|
| `NonRetryableFailure` | the exception is declared non-retryable and is never retried |
| `MaximumAttemptsReached` | every allowed attempt was used |
| `Timeout` | a schedule-to-start or schedule-to-close bound elapsed |
| `RetryPolicyNotSet` | no retry policy applied |
| `InProgress` | not final; another attempt is expected |

`InProgress` records a failure that is **not** an outcome. It appears when retries are delegated to
the Temporal server. By design, it does not count as a terminal outcome, so the next attempt runs.

The state mirrors Temporal's `RetryState`, which is also a field on the failure rather than a
separate event type.

## Attempt counting

`RetryLimit::ofAttempts(3)` allows **three executions in total**, the first one included, as on
Temporal.

Without an explicit limit, attempts are **unlimited**. An activity that always fails is retried
forever, and its workflow never fails. See [Options](../../options/#retrylimit).

## `kind` of `WorkflowExecutionFailed`

A failure that the workflow code does not catch ends the execution with
`WorkflowExecutionFailed`. Its `kind` field gives the origin of the failure:

| Kind | Origin |
|---|---|
| `unhandled_activity_failure` | an activity failure the workflow did not catch |
| `unhandled_declared_activity_failure` | a declared business failure the workflow did not catch |
| `unhandled_catastrophic_activity_failure` | an activity failure that could not be journaled |
| `unhandled_activity_superseded` | the workflow awaited an activity that had lost a race |
| `workflow_handler_failure` | the workflow code itself threw |
| `terminated_by_parent` | a parent closed with `ParentClosePolicy::Terminate` |

On the Temporal backend, the kind is stored in the `ApplicationFailureInfo` details. Reading the
history back rebuilds a typed `WorkflowExecutionFailed` instead of a bare message, and keeps the
name of the failing activity.

## Related pages

- [Diagnose a failed activity or workflow](../diagnose/)
- [Stop retrying an exception](../stop-retrying/)
- [Retries over Symfony Messenger](../messenger/)
- [Compensating](../../cancellation/#compensating)
