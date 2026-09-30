---
title: Failures and retries
weight: 26
---

# Failures and retries

When an activity fails, Durable can retry it. Each attempt and the final outcome are recorded in
the journal. An activity is a unit of side effect in a workflow, such as an HTTP call,
a database write or an e-mail. The journal is the append-only record of everything an execution
decided and received. Both terms are in the [glossary](../glossary/).

Pick the page that matches what you need. To undo the effects of a failed process, see
[Compensating](../cancellation/#compensating).

## Diagnose a failed activity or workflow {#why-an-activity-stopped-retrying}

[Diagnose a failed activity or workflow](diagnose/): an activity that stopped retrying too early
or never stops, a `messenger:failed:retry` that runs nothing, an *early resume* line in the logs,
a workflow that ended with `WorkflowExecutionFailed`.

## Stop retrying an exception {#declaring-an-exception-non-retryable}

[Stop retrying an exception](stop-retrying/): declare an exception non-retryable, so that an
error no later attempt can fix, such as a refused card, is never retried.

## Failure events and states {#what-the-journal-records-for-one-activity}

<span id="attempt-counting"></span><span id="when-the-workflow-itself-fails"></span>
[Failure events and states](events/): the journal events of one activity, the `retryState`
values, how `RetryLimit` counts attempts, and the `kind` values of `WorkflowExecutionFailed`.

## Retries over Symfony Messenger {#which-retry-knob-lives-where-symfony-messenger}

[Retries over Symfony Messenger](messenger/): which retry settings belong to Durable and which to
Messenger, why there is only one retry counter, and the two messages that bypass `max_retries`.
