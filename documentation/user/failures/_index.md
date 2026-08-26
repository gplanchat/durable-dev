---
title: Failures and retries
weight: 26
---

# Failures and retries

An activity that fails is not one event but several, and telling them apart is what lets you decide
whether to compensate, alert, or let the workflow die.

---

## What the journal records for one activity

| Event | Meaning |
|---|---|
| `ActivityScheduled` | the workflow asked for it |
| `ActivityTaskStarted` | one attempt began — one per attempt |
| `ActivityTaskFailed` | **one attempt failed**, whether or not another follows |
| `ActivityTaskCompleted` | one attempt succeeded |
| `ActivityCompleted` | final outcome: success |
| `ActivityFailed` | final outcome: failure |
| `ActivityCancelled` | final outcome: removed before completing |
| `ActivityCatastrophicFailure` | the failure itself could not be safely journaled |

`ActivityTaskFailed` matters: without it, an attempt that failed and was followed by a success left
**no trace at all**. The first error simply vanished from the journal.

---

## Why an activity stopped retrying

`ActivityFailed` carries a `retryState` telling you which of four situations you are in — they
used to be indistinguishable:

```php
use Gplanchat\Durable\Failure\ActivityRetryState;

$failed->retryState();     // ActivityRetryState
$failed->isStalled();      // true when attempts were exhausted
```

| State | Meaning |
|---|---|
| `NonRetryableFailure` | the exception is declared non-retryable — it will never be retried |
| `MaximumAttemptsReached` | every allowed attempt was consumed |
| `Timeout` | a schedule-to-start or schedule-to-close bound elapsed |
| `RetryPolicyNotSet` | no retry policy applied |
| `InProgress` | not final — another attempt is expected |

`InProgress` is how a failure that is **not** an outcome is recorded. It appears when retry is
delegated to the Temporal server, and it deliberately does not count as a terminal outcome, so the
next attempt really runs.

The state mirrors Temporal's `RetryState`: there, too, this is a **field on the failure**, not a
distinct event type.

---

## Declaring an exception non-retryable

```php
use Gplanchat\Durable\Activity\ActivityOptions;

ActivityOptions::of(5, nonRetryableExceptions: [PaymentRefusedException::class]);
```

A refused card will not get better on the third attempt. On the Temporal backend this becomes the
retry policy's `nonRetryableErrorTypes`, so the **server** stops retrying too — not just the PHP
worker.

---

## Attempt counting

`RetryLimit::ofAttempts(3)` means **three executions in total**, as on Temporal — not three retries
after a first try.

> [!WARNING]
> With no explicit limit, attempts are **unlimited**. An activity that always fails will retry
> forever and the workflow will never fail. See [Options](../options/#retrylimit).

---

## When the workflow itself fails

An error the workflow does not handle produces `WorkflowExecutionFailed`, whose `kind` says where
it came from:

| Kind | Origin |
|---|---|
| `unhandled_activity_failure` | an activity failure the workflow let escape |
| `unhandled_declared_activity_failure` | a declared business failure it let escape |
| `unhandled_catastrophic_activity_failure` | an activity failure that could not be journaled |
| `unhandled_activity_superseded` | a race loser it awaited anyway |
| `workflow_handler_failure` | the workflow code itself threw |
| `terminated_by_parent` | a parent closed with `ParentClosePolicy::Terminate` |

On the Temporal backend this kind travels in the `ApplicationFailureInfo` details, so reading the
history back reconstructs a typed `WorkflowExecutionFailed` rather than a bare message. The failing
activity's name survives the round trip.
