---
title: Nexus operations
weight: 29
---

# Nexus operations

Nexus lets a workflow call an operation owned by another team, another namespace, another
deployment — without either side knowing the other's workflows. Durable does both roles: it
**calls** operations, and it **serves** them.

Serving requires the **Temporal backend**. The in-memory and DBAL backends have no cross-namespace
route, and they say so rather than pretending — see [Backends](../backends/).

---

## Calling an operation

```php
$result = $env->await($env->nexusOperation(
    endpoint: 'paiements',
    service: 'facturation',
    operation: 'encaisser',
    payload: ['amount' => 1200, 'currency' => 'EUR'],
    timeouts: new NexusOperationTimeouts(scheduleToClose: Duration::minutes(5)),
));
```

`nexusOperation()` assembles; `await()` waits. That is the same rule as everywhere else — see
[Creating a workflow](../workflows/).

The payload travels **as you wrote it**. There is no Durable envelope around it, so a handler
written with the Go, Java or TypeScript SDK reads the fields it declares.

Whether the handler answers immediately or hours later changes nothing here: the workflow waits on
the operation, and the result arrives when it arrives.

---

## Serving an operation

Declare the service and the operation on a handler:

```php
use Gplanchat\Durable\Bundle\Attribute\AsNexusOperationHandler;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;

#[AsNexusOperationHandler(service: 'facturation', operation: 'encaisser')]
final class Encaisser
{
    public function __invoke(mixed $payload): NexusOperationResponse
    {
        return NexusOperationResponse::completed(['receipt' => 'r-1234']);
    }
}
```

The pair `(service, operation)` is the whole address: an incoming task is routed by it and nothing
else. Both names are checked when the container is built, because a typo produces a handler that
nothing ever reaches — and the server has nothing to complain about.

### Answering now, or answering later

`NexusOperationResponse` has two forms, and choosing between them is the one decision that matters.

```php
// Now — you have the answer.
return NexusOperationResponse::completed(['receipt' => 'r-1234']);

// Later — a workflow will produce it.
return NexusOperationResponse::fulfilledByWorkflow('Encaissement', $payload);
```

**A handler has roughly nine seconds.** That is not the operation's budget, it is the budget for
answering *this task*: the caller's `scheduleToClose` may be five minutes, but the task itself
carries a `request-timeout` of about nine seconds. A handler still working when it expires has its
task redelivered — and starts over. Measured redeliveries: ~9.9 s, ~20.7 s, ~33.6 s.

So `completed()` is for a lookup, a validation, a computation you already know is fast. Anything
that talks to a payment provider, waits on a human, or retries for a day belongs in a workflow, and
that is what `fulfilledByWorkflow()` is for.

When you name a workflow, Durable starts it with the caller's callback attached, and the server
delivers that workflow's result to the caller when it finishes. Your handler is not called again.

### Cancellation

If the caller cancels, Durable cancels the workflow fulfilling the operation. You do not write a
cancellation hook: your workflow already observes cancellation, and compensates, exactly as
described in [Cancellation](../cancellation/).

A cancellation only reaches a handler for an operation that has **started** — an operation still
waiting for its first answer has nothing to cancel on your side.

### Failing

Raise, and the operation fails:

```php
throw new \RuntimeException('the payment provider is unreachable');
```

An ordinary exception is reported as `INTERNAL`, which is **retryable** — the task comes back, up to
the operation's budget. That is right for an outage and wrong for a bad request, which will never
improve. For a terminal refusal, say which kind it is:

| terminal — do not retry | retryable — try again |
|---|---|
| `BAD_REQUEST`, `UNAUTHENTICATED`, `UNAUTHORIZED` | `RESOURCE_EXHAUSTED`, `INTERNAL` |
| `NOT_FOUND`, `NOT_IMPLEMENTED`, `CONFLICT` | `UNAVAILABLE`, `UPSTREAM_TIMEOUT`, `REQUEST_TIMEOUT` |

The line is *whose fault is it*. A malformed request or a missing right will not be fixed by
retrying; an overload or an upstream timeout might. The table is nexus-rpc's, shared by every
language SDK — not a Durable invention.

An operation nobody serves is answered `NOT_IMPLEMENTED`, terminal, and the worker keeps serving
its other operations.

---

## Running the worker

Serving needs a worker on the Nexus task queue. It is a Messenger transport, like the activity
worker:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            durable_temporal_nexus:
                dsn: '%env(DURABLE_DSN)%'
                options:
                    purpose: nexus_worker
```

```bash
php bin/console messenger:consume durable_temporal_nexus --time-limit=3600
```

The queue comes from the DSN. `nexus_task_queue` sets it; **it defaults to the workflow task
queue**, because a Nexus endpoint targets a queue and the server only delivers where someone polls.
A queue nobody serves is an endpoint that never answers, without an error anywhere.

---

## Registering the endpoint

An endpoint is a cluster-wide object, created once by an operator, not by the application:

```bash
temporal operator nexus endpoint create \
    --name paiements \
    --target-namespace production \
    --target-task-queue durable-workflows
```

The `--target-task-queue` must be the queue your Nexus worker polls.

---

## If you declare a handler on the wrong backend

The container refuses to build, and names what is missing:

```
durable.nexus_handler: a Nexus handler is declared, but this backend cannot route
Nexus operations. Nexus needs the Temporal backend — set durable.temporal.dsn.
Declared by: app.encaisser.
```

This is deliberate, and it is not how the caller side behaves. A call on a backend with no route
fails at the call — you find out immediately. A *handler* with no route is not a call that fails, it
is a service that never receives anything, silently. There is no request left to fail, so the
refusal happens when the application starts.

---

## See also

- [Backends](../backends/) — which backend can route Nexus, and why the others refuse.
- [Cancellation](../cancellation/) — what your fulfilling workflow does when the caller cancels.
- [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md) — the decision record, and the measurements behind it.
