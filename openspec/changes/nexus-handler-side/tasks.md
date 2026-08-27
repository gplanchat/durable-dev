# Tasks

## 1. Probe before designing anything

- [ ] 1.1 Serve an operation with an official SDK (Go) and call it from a Durable workflow.
      **Not done, and re-scoped.** A raw PHP poller read the wire directly, which answered 1.2–1.7
      without it. Its remaining purpose is sharper: our caller wraps the payload in a private
      `{operationId, payload}` envelope, so a Go handler is what proves whether a non-Durable
      handler can serve a Durable caller at all.
- [x] 1.2 `PollNexusTaskQueue` semantics. Long-poll returns after ~11 s on an idle queue with an
      **empty task token and null request** — a success, not an error.
- [x] 1.3 `RespondNexusTaskCompleted` with a synchronous result. **Accepted, full round trip**: the
      calling workflow received the payload. No new gRPC plumbing is needed.
- [x] 1.4 The asynchronous shape. The server **accepts** an async token and records
      `NEXUS_OPERATION_STARTED`; `callback: temporal://system` confirms the server correlates the
      completion and the handler is not involved again. **But our own caller refuses async**, by
      design and loudly — so this shape needs caller-side work too. Scope correction, see 3.4.
- [x] 1.5 Cancellation. A cancel task reaches the handler **only for a started operation**. With the
      start task still pending, cancelling the caller produced `NEXUS_OPERATION_CANCEL_REQUESTED`
      caller-side and **no handler task at all**. The cancel path is coupled to the async shape.
- [x] 1.6 `RespondNexusTaskFailed`. An `INTERNAL` handler error is **retryable**: accepted, then the
      task is redelivered — 3 times in 25 s. It does not fail the operation.
- [x] 1.7 A handler that never responds: redelivered at ~9.9 s, ~20.7 s, ~33.6 s — on the ~9 s
      `request-timeout` clock, not the operation timeout.
- [x] 1.8 `design.md` rewritten from what was measured.

## 1bis. What the probe added to the work

- [ ] 1b.1 **Two budgets.** `request-timeout` (~9 s) bounds the answer to one task;
      `operation-timeout` bounds the operation. A handler cannot hold a task while it works. Decide
      whether synchronous-only is still worth shipping given how narrow ~9 s makes it.
- [ ] 1b.2 **The payload envelope.** Decide: teach handlers our `{operationId, payload}` envelope —
      which locks Durable into serving only Durable — or correlate by scheduled event id and stop
      wrapping. This is a caller-side change either way and it belongs to this review.
- [ ] 1b.3 **Retryable versus terminal handler errors.** `INTERNAL` is retried until the operation
      times out. Establish which error types are terminal before writing the failure path, or a
      handler that raises on bad input will retry for the whole operation budget.

## 2. The task worker

- [ ] 2.1 RED: a poll returning a task routes to a registered handler.
- [ ] 2.2 GREEN: poll loop, dispatch, `RespondNexusTaskCompleted` for the synchronous shape.
- [ ] 2.3 Failure path: a throwing handler responds `RespondNexusTaskFailed`, classified the way
      the caller side already classifies.
- [ ] 2.4 An operation nobody serves: the response says so, and the worker keeps polling.

## 3. The asynchronous shape — both sides

- [ ] 3.1 A handler that fulfils an operation with a workflow.
- [ ] 3.2 The caller receives that workflow's result as the operation's result.
- [ ] 3.3 The workflow fails: the caller sees an operation failure, classified.
- [ ] 3.4 **Caller side, found by the probe.** The caller refuses an async response today. Teach it
      to receive the completion the server delivers, so a Durable workflow can call an asynchronous
      operation at all — without this, 3.1 and 3.2 cannot be observed end to end from PHP.

## 4. Cancellation

- [ ] 4.1 A caller that cancels reaches the handler. 1.5 established the form: a cancel task
      arrives **only for a started operation**, so this depends on 3.4.
- [ ] 4.2 A handler observes the cancellation rather than discovering it on response.
- [ ] 4.3 Cancelling an operation already fulfilled asynchronously: what happens to the workflow.

## 5. Registration and refusal

- [ ] 5.1 Declaring a served operation; the bundle wires it.
- [ ] 5.2 A worker command for the Nexus task queue.
- [ ] 5.3 Registering a handler on a backend that cannot route refuses **at startup**, naming the
      backend — not at request time, since no request will come.

## 6. End to end

- [ ] 6.1 Caller and handler in the same integration test, against a real server, both shapes.
- [ ] 6.2 Cross-check against the Go reference trace from 1.1: same messages, same order.
- [ ] 6.3 Full unit and integration suites green.

## 7. Say it in the documentation

- [ ] 7.1 A DUR: the worker, the two shapes, the cancellation form, the registration-time refusal.
- [ ] 7.2 DUR036's "separate change" gains a forward pointer, and its caller-only framing is
      corrected.
- [ ] 7.3 A user page for serving an operation.
- [ ] 7.4 The comparison page: caller-only stops being a limitation, and the section says what it
      now means that no other PHP implementation serves Nexus.
