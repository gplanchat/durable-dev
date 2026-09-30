---
title: Stop retrying an exception
weight: 20
---

# Stop retrying an exception

This guide shows how to stop Durable from retrying an activity when a given exception is thrown.
An activity is a unit of side effect in a workflow, such as an HTTP call, a database write or an
e-mail; see the [glossary](../../glossary/).

Use it for errors that no later attempt can fix. A refused card is still refused on the third
attempt.

## Declare the exception non-retryable

List the exception in `nonRetryableExceptions` when you build the activity options:

```php
use Gplanchat\Durable\Activity\ActivityOptions;

ActivityOptions::of(5, nonRetryableExceptions: [PaymentRefusedException::class]);
```

When the activity throws `PaymentRefusedException`, the attempt fails and no other attempt runs.
The `ActivityFailed` event records `retryState` = `NonRetryableFailure`.

On the Temporal backend, this list becomes the `nonRetryableErrorTypes` of the retry policy. The
Temporal server then stops retrying as well as the PHP worker.

On Symfony Messenger, the failure is written to the journal, then thrown as
`UnrecoverableMessageHandlingException`. Messenger does not retry it, and sends it to
`failure_transport` if you configured one. See [Retries over Symfony Messenger](../messenger/).

## Limit the other retries

`nonRetryableExceptions` only covers the exceptions you list. Every other exception stays
retryable, and without an explicit limit, attempts are **unlimited**. To set one,
see [Options](../../options/#retrylimit). `RetryLimit::ofAttempts(3)` allows three executions in
total, the first one included.

## Related pages

- [Failure events and states](../events/)
- [Diagnose a failed activity or workflow](../diagnose/)
