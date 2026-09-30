---
title: Retries over Symfony Messenger
weight: 40
---

# Retries over Symfony Messenger

When activities run over Symfony Messenger, two systems could retry a failed activity. This page
explains how the work is split between them, and why an activity failure has only one retry
counter. An activity is a unit of side effect in a workflow, such as an HTTP call, a database write
or an e-mail. The journal is the append-only record of everything an execution decided and
received. See the [glossary](../../glossary/).

## Which system owns each retry setting

Durable decides whether and when an activity is retried. Messenger delivers the messages.

| Question | Decided by | Setting |
|---|---|---|
| Is this failure worth another attempt? | Durable | `nonRetryableExceptions` on `ActivityOptions` |
| How many attempts, and how long between them? | Durable | `retryLimit`, `initialInterval`, `backoffCoefficient`, `maximumInterval`; `max_activity_retries` caps them all, except on Temporal |
| Where does a failed activity go for an operator to look at? | Messenger | `failure_transport` |
| Delivery, acknowledgement, the transport itself | Messenger | the `durable_activities` transport |

## One retry counter

Durable schedules its own retries. A retried attempt is a new message, queued with its delay. The
handler does not throw for a failure that Durable retries, so Messenger's `retry_strategy` does not
apply to activity failures, and there is no second counter to keep in step with the first.

A **non-retryable** failure takes another path. Durable writes it to the journal, then throws it as
`UnrecoverableMessageHandlingException`. Messenger does not retry it, and sends it to
`failure_transport` if you configured one. Running `messenger:failed:retry` on that message runs
nothing again, because the attempt is already in the journal.

## Two messages that bypass `max_retries`

Two kinds of message bypass `max_retries` on purpose. Each one waits for something that another
worker settles.

**An activity attempt that another worker is running.** On a DBAL journal, Durable claims each
attempt before running it. Laravel does the same; an in-memory journal runs in one process and
claims nothing. A copy that finds the claim taken is retried later, on the transport's
`retry_strategy`. It then finds the attempt in the journal or, if the worker holding the claim
died, runs the attempt once the claim expires.

**A resume that arrives before the fact it announces.** A worker sends one resume before it
journals what the resume announces (an activity's outcome, a signal, a child's outcome, a fired
timer), and another one after. The early resume fails on purpose and is retried. After ten
redeliveries it is acknowledged, and the `messenger` channel logs an `info` line:
*"dropped an early resume of execution … the resume sent after its append carries the run"*. The
run is not lost, and nothing reaches `failure_transport`.

## Related pages

- [Stop retrying an exception](../stop-retrying/)
- [Diagnose a failed activity or workflow](../diagnose/)
- [Failure events and states](../events/)
