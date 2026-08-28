# DUR045 — Serving a Nexus operation: one worker, two shapes, and a refusal at startup

## Status

Accepted

## Context

[DUR036](DUR036-nexus-caller-only-and-the-backend-asymmetry.md) decided the caller side and said
the handler side would be a separate change. This is that change.

**Nothing here was designed before it was measured.** Eight probes ran against a live
`temporal server start-dev` 1.31.2, and three of them overturned a hypothesis the design had
written down. The shape below is what survived.

### The two budgets

A start task carries two deadlines, not one:

```
header: request-timeout=8.998s , operation-timeout=89997ms
```

`request-timeout` (~9 s) bounds the answer to **this task**. `operation-timeout` bounds the whole
operation. A handler with real work to do cannot hold the task: past nine seconds it is redelivered
and the work starts over — measured at ~9.9 s, ~20.7 s, ~33.6 s for a handler that never answers.

This is why "synchronous only" is a much narrower option than it sounds, and why the asynchronous
shape was built first.

### What actually correlates an asynchronous answer

The design hypothesised that "the handler responds once, naming a workflow, and the server
correlates that workflow's completion without the handler being involved again". It holds — but not
through the mechanism the wording suggests.

Measured both ways. A workflow started with the start task's `callback` and `callbackHeader` in
`StartWorkflowExecutionRequest.completion_callbacks` completes the caller's operation with its own
result, the handler never polled again. Remove that attachment and change nothing else: the caller's
history stops at `NEXUS_OPERATION_STARTED` and no outcome ever arrives, however valid the token.

**The token is not the mechanism, only the identifier.** What correlates is the callback, and
`completion_callbacks` can only be set at start.

### An empty poll is a success

A poll on an idle queue returns after ~11 s with an empty task token and a null request. Treating
that as an error would make the loop shout through the nominal case.

## Decision

### One worker, and it shares nothing with the workflow worker

`TemporalNexusWorker` polls, routes to the declared handler, and responds. It has no history, no
replay, no determinism, no slots. Serving through the existing workflow task worker would drag a
replay engine through a path that never replays.

It is exposed as a Messenger transport, `purpose=nexus_worker`, exactly as the activity worker is.
`messenger:consume` already knows how to hold a loop, restart it, bound it in time and supervise it.

### One typed contract, and the deferred form is declared rather than returned

A Nexus operation is declared on a contract interface, exactly as an activity is, and both sides of
the boundary read the same object: the caller derives a typed stub from it, the handler implements
it.

```php
#[AsNexusService('billing')]
interface BillingServed                          // answered immediately
{
    #[AsNexusOperation('verify')]
    public function verify(Order $order): Verdict;
}

#[AsNexusService('billing')]
interface BillingContract extends BillingServed  // + what a workflow fulfils
{
    #[AsNexusOperation('charge')]
    public function charge(Order $order, int $amount): Receipt;
}

#[AsNexusServiceHandler(contract: BillingServed::class)]
final class Billing implements BillingServed { /* … */ }

#[AsWorkflow]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class Charge { /* … */ }
```

**The contract splits in two, and that is what removes the empty methods.** An operation fulfilled
by a workflow has no handler body; without the split, PHP would demand one whose only job is to say
there is nothing to write. The handler implements the served interface, the caller reads the one
that extends it.

**The workflow claims the operation, not the contract.** Naming a server-side class inside an
interface the caller reads would leak the implementation across the very boundary Nexus exists to
draw — and the declaration belongs where the code is.

A contract shaped as `startedAsynchronously(string $token)` was rejected for a different reason: it
would hand the handler the one piece that correlates nothing, and hand it too late — the start it
needed to influence having already happened. The worker therefore **starts the workflow before it
answers**. The order is not cosmetic.

This is DUR039 applied to Nexus. That record removed `activity(string $name, array $payload)` from
the authoring surface because a typo there produces an activity that is never scheduled rather than
a type error; the Nexus caller had kept exactly that shape, with the same name written twice — once
by the caller, once by the handler — and nothing tying the two.

### Cancelling an operation is cancelling the workflow that carries it

A cancellation task arrives **only for a started operation** — with the start task still pending,
cancelling the caller produces `NEXUS_OPERATION_CANCEL_REQUESTED` on its side and no handler task at
all. When it does arrive, it **names the operation token returned at start**, which is the workflow
this worker started. So the worker cancels that workflow and acknowledges the task.

The handler function is not called again, and that is not a gap. What carries the operation is a
workflow, and a workflow already observes its own cancellation, with its compensations. A
handler-side hook would duplicate that path without adding to it.

If the workflow finished between the caller's request and the worker's poll, the cancellation is
**still acknowledged** rather than failed: the operation is already settled, and an error answer
would have the task redelivered every ~9 s for nothing.

### Failure classification is nexus-rpc's, verbatim

Not invented here. The rule lives in the **nexus-rpc** SDK, shared by every language: an explicit
`RetryBehavior` wins outright; failing that, the error type decides.

| not retryable | retryable |
|---|---|
| `BAD_REQUEST`, `UNAUTHENTICATED`, `UNAUTHORIZED` | `RESOURCE_EXHAUSTED`, `INTERNAL` |
| `NOT_FOUND`, `NOT_IMPLEMENTED`, `CONFLICT` | `UNAVAILABLE`, `UPSTREAM_TIMEOUT`, `REQUEST_TIMEOUT` |

The line is *whose fault is it*. An ordinary exception maps to `INTERNAL`, and therefore retries —
what every other SDK does. A handler wanting a terminal refusal says so with its own type.

An operation nobody serves is answered `NOT_IMPLEMENTED`, **terminal**. Retryable, it would be
re-asked every ~9 s for the whole operation budget, for an answer that cannot change: the handler
will not appear between two attempts.

### The refusal belongs at registration, not at request time

This is the asymmetry with the caller, and it is the reason this record exists rather than a
paragraph in DUR036.

The caller refuses a Nexus call **at call time** on a backend that cannot route, because that is when
the mistake becomes visible. A handler is the opposite: one declared on a backend with no route is
not a call that fails, it is a service that **never receives anything**, without a line of log.
There is no request to fail later.

So `NexusHandlerPass` fails at **container compile time**, naming the backend, the missing
configuration key, and the services that declared a handler. A container with no handler at all is
left alone — refusing there would break every application that does not use Nexus.

The two names of the `(service, operation)` pair are validated at the same moment and for the same
reason: an incoming task is routed by that pair and nothing else, so a typo produces a handler
nothing ever reaches, and the server has nothing to complain about.

### The Nexus task queue follows the workflow task queue

`nexus_task_queue` in the DSN, defaulting to the workflow task queue rather than to a name of its
own. A Nexus endpoint targets a queue and the server delivers there only if someone polls it: a
default queue nobody serves is an endpoint that never answers, silently.

## Consequences

- The bridge gains a second worker and the Symfony bundle a second consumer. That is the bulk of the
  diff, and it should be read as such.
- Unit tests of the worker assert what it **sends**, against a mocked gRPC client. Whether the
  server **accepts** it is a different question — a malformed `syncSuccess` or a badly attached
  callback passes a mock assertion and is rejected on the wire. Every branch therefore also has an
  integration case against a real server.
- DUR036's "caller side only" framing is superseded on that one point. Its backend asymmetry and its
  value-object rules stand unchanged.

## Alternatives considered

- **Synchronous completion only, asynchronous later.** It halves the unknowns, and ships the half a
  plain HTTP endpoint already does while deferring the half that is the reason to use Nexus. The
  ~9 s request budget is what settled it.
- **A handler-side cancellation hook.** Rejected above: the workflow already observes cancellation.
- **A dedicated console command for the worker.** `messenger:consume` does it better.
- **Serving through the existing workflow task worker.** It drags replay through a path with no
  history.
