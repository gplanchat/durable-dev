# Design

## What was probed, and what was measured

**Probed: the whole task lifecycle, against `temporal server start-dev` 1.31.2.** The instrument
was not the Go SDK the tasks called for, but something more direct: a raw PHP poller on the
handler's task queue, built from the generated stubs already in `src/Bridge/Temporal/Api`, with a
Durable workflow as the caller. It shows the wire itself rather than a mirror of it.

**A full round trip works today.** A raw poller answered `RespondNexusTaskCompleted` with a sync
payload and the calling workflow received `['greeting' => 'hello ada']`. Serving Nexus needs no new
gRPC plumbing — `PollNexusTaskQueue`, `RespondNexusTaskCompleted` and `RespondNexusTaskFailed` are
generated and functional. What is missing is the poll loop, the dispatch, and the declaration
surface.

### The start task, as it actually arrives

```
variant       : start_operation
service       : probe.service
operation     : greet
requestId     : c8074689-…                     (also as header nexus-request-id)
callback      : temporal://system
payload       : {"operationId":"01a0…","payload":{"name":"ada"}}
links         : 1  (nexus-link back to the caller's NexusOperationScheduled event)
capabilities  : temporalFailureResponses = true
header        : request-timeout=8.998s , operation-timeout=89997ms
```

**Two budgets, not one, and this is the finding that shapes the worker.** `request-timeout` (~9 s)
bounds the response to *this task*. `operation-timeout` (90 s here — the caller's
schedule-to-close) bounds the whole operation. A handler with real work to do cannot hold the task:
it has roughly nine seconds to answer, whatever the operation's budget. That is what the
asynchronous shape is for, and it means "synchronous only" is a much narrower option than it
sounds.

`callback: temporal://system` confirms the hypothesis this design was written on: the **server** is
the callback target, so a handler that answers asynchronously is not asked to observe anything
afterwards.

### The asynchronous shape: accepted by the server, refused by our own caller

Answering `RespondNexusTaskCompleted` with an `asyncSuccess` carrying an operation token **is
accepted** — the caller's history records `NEXUS_OPERATION_STARTED`. Then the calling workflow
failed, on purpose, with our own message:

> Nexus operation … started asynchronously: the handler returned a token and will complete by
> callback later. This increment only supports synchronous operations, and nothing here can receive
> that callback — the wait would never end.

This is the DUR036 discipline applied to the caller, and it is the right behaviour. It is also a
**scope correction for this change**: the asynchronous shape is not only handler-side work. To call
an asynchronous operation, the caller must learn to receive the completion. Two sides, one shape.

### Failure and silence both redeliver, on the request-timeout clock

| What the handler does | What the server does |
|---|---|
| `RespondNexusTaskFailed(INTERNAL)` | accepted; task **redelivered** — 3 times in 25 s |
| nothing at all | redelivered at ~9.9 s, ~20.7 s, ~33.6 s |

Redelivery follows the ~9 s `request-timeout`, not the operation timeout. An `INTERNAL` handler
error is therefore **retryable** and does not fail the operation — so a handler that raises on bad
input would be retried until the operation times out. Distinguishing retryable from terminal
handler errors is real work, not a detail.

### Cancellation reaches the handler only for a started operation

Cancelling the caller wrote `NEXUS_OPERATION_CANCEL_REQUESTED` in its history and the run cancelled
cleanly — but **no `cancel_operation` task arrived** on the handler's queue in 40 s. The operation
had never been started: no token, nothing to cancel handler-side.

So the handler's cancellation path is coupled to the asynchronous shape. No async, no cancel task.

### An empty poll is a success, not an error

A poll on an idle queue returned after 11.2 s with an **empty task token and a null request**. The
loop must treat that as "nothing to do", not as a failure.

### Still worth doing: the Go cross-check

Task 1.1 asked for a Go handler as reference. It was not needed to read the wire — but one finding
makes it more valuable, not less. **Our caller wraps the user payload**:
`TemporalWorkflowCommandBuffer::scheduleNexusOperation()` sends
`{"operationId": …, "payload": …}`, a Durable-private envelope, because
`TemporalExecutionHistory` reads that id back to correlate the operation.

A handler written with any other SDK receives that envelope, not the caller's payload.

**Measured (task 1.1), and the result decides it.** A Nexus operation served by the Go SDK and
called from a Durable workflow **completes** — and the handler receives an empty payload:

```
handler declares : Greeting{ Name string `json:"name"` }
handler receives : {"name":""}
handler answers  : "hello "          (expected "hello ada")
```

Nothing raises. Not the server, not the Go SDK, not us. The caller gets a well-formed reply computed
from nothing.

That settles the question the paragraph above left open. Teaching handlers our envelope would only
fix *our* handlers and leave every other SDK's quietly wrong — and the symmetric case is just as
bad: a Durable handler would look for an `operationId` a Go caller never sent.

**The envelope has to go.** The scheduled event id, which history already carries, is the
correlation the caller needs; the payload should travel as the caller wrote it. That is a
caller-side change and it breaks the wire format for in-flight Nexus operations, which is why it is
task 1b.2 and not a footnote.

## The worker is new plumbing, not an extension

The workflow task worker polls, replays a fiber, and responds with commands. A Nexus task worker
polls, calls one handler, and responds with a result. They share the poll-and-respond shape and
nothing else: no history, no replay, no determinism, no slots. Reusing the workflow worker would
mean carrying a replay engine through a code path that never replays.

The consequence for the review: this change adds a second worker to the bridge, and the Symfony
bundle grows a second consumer. That is the bulk of the diff and it should be read as such.

## The two completion shapes are not two features

Nexus lets an operation answer immediately or start a workflow and answer when it finishes. The
asynchronous shape is the interesting one: it is how a Nexus operation becomes durable rather than
just remote. It is also where the correlation between the operation and the workflow that fulfils
it lives, which is the thing this design knows least about.

Hypothesis, to be falsified: the handler responds once, naming a workflow, and the server correlates
that workflow's completion to the operation without the handler being involved again.

**Measured (§3.1 probe), and it holds — but the mechanism is not the one the wording suggests.**
A raw poller answered a start task asynchronously, having first started a second workflow carrying
the task's `callback` and `callbackHeader` in `StartWorkflowExecutionRequest.completion_callbacks`.
Completing that workflow wrote `NEXUS_OPERATION_COMPLETED` into the *caller's* history with the
workflow's result. The handler was never polled again.

Run with the callback attachment removed and nothing else changed, the caller's history stops at
`NEXUS_OPERATION_STARTED` and no outcome ever arrives, however valid the token.

**So the token is not the mechanism, only the identifier.** What correlates is the callback, and
`completion_callbacks` can only be set at start. This decides the handler's contract: a handler
says *"fulfil this with workflow X"*, and the plumbing attaches the callback and derives the token.
A contract shaped as "return a token" would hand the handler the one piece that correlates nothing,
and by the time it returned, the start it needed to influence would already have happened.

## Failures reuse the caller's classification, or the mismatch is a finding

The caller side already classifies Nexus failures. A handler produces failures on the other side of
the same wire, and the default is that the same vocabulary describes both. Where it does not, the
answer is to record which distinction is missing and why — not to grow a parallel hierarchy that
means almost the same thing.

## Refusing to serve, at registration

The caller side refuses a Nexus call at call time on a backend that cannot route, because that is
when the mistake becomes visible. A handler is different: registration happens at startup, and a
handler registered on a backend with no route is not a call that fails, it is a service that
silently never receives anything. So the refusal belongs at **registration**, not at request time —
there is no request to fail.

## Alternatives considered

- **Handler side as a separate package.** It needs the caller's value objects and its failure
  classification; splitting them would duplicate both or invert the dependency.
- **Serving through the existing workflow task worker.** Discussed above: it drags replay through a
  path that has no history.
- **Synchronous completion only, asynchronous later.** Tempting, and it halves the unknowns. It also
  ships the half that a plain HTTP endpoint already does, and defers the half that is the reason to
  use Nexus at all. If the probe shows the asynchronous shape is much larger than expected, this
  becomes the right split — but as a finding, not as an opening move.
