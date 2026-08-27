# Tasks

## 1. Probe before designing anything

- [x] 1.1 A Nexus operation served by the **Go SDK**, called from a Durable workflow. The question
      was whether a non-Durable handler can serve a Durable caller at all. The answer is worse than
      "no".
      **It works, and it silently corrupts the payload.** The call completed end to end — the Go
      handler was reached, ran, and its reply came back to the workflow. But the handler, declaring
      `Greeting{Name string \`json:"name"\`}`, received `{"name":""}` and answered `hello ` instead
      of `hello ada`.
      The cause is our envelope. The caller sends `{"operationId": …, "payload": {"name":"ada"}}`;
      the handler deserialises that into its own type, finds no `name` at the top level, and gets a
      zero value. **No error is raised anywhere** — not by the server, not by the SDK, not by us.
      A hard failure would have been better: it would have been found the first time. This is a
      wrong answer that looks like a right one, which is the failure mode this codebase treats as
      the most expensive (DUR036, DUR042).
      It also means the reverse is true and equally quiet: a **Durable handler** would receive a Go
      caller's bare payload and look for an `operationId` that is not there.
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

- [x] 1b.1 **Two budgets — decided: both shapes ship together.** `request-timeout` (~9 s) bounds the
      answer to one task; `operation-timeout` bounds the operation. A handler cannot hold a task
      while it works.
      The design reserved a synchronous-only fallback. Nine seconds is what killed it: that split
      would only serve handlers answering almost immediately — which a plain HTTP endpoint already
      does — while deferring the shape that is the reason to use Nexus at all.
      Cost accepted: the asynchronous shape also carries a **caller-side** prerequisite (3.4), so
      section 3 is larger than the milestone it belongs to. Taken knowingly rather than discovered.
- [x] 1b.3 **Retryable versus terminal — decided: the nexus-rpc classification, verbatim.**
      Not invented here. The rule lives in the **nexus-rpc** SDK, shared by every language, and has
      two tiers: an explicit `RetryBehavior` wins outright; failing that, the **error type** decides.

      | not retryable | retryable |
      |---|---|
      | `BAD_REQUEST`, `UNAUTHENTICATED`, `UNAUTHORIZED` | `RESOURCE_EXHAUSTED`, `INTERNAL` |
      | `NOT_FOUND`, `NOT_IMPLEMENTED`, `CONFLICT` | `UNAVAILABLE`, `UPSTREAM_TIMEOUT`, `REQUEST_TIMEOUT` |

      The line is *whose fault is it*: a malformed request or a missing right will not improve by
      retrying; an overload or an upstream timeout might.

      **An ordinary exception maps to `INTERNAL`, and therefore retries** — which is what every
      other SDK does ("Arbitrary errors from handler methods are turned into
      `HandlerErrorTypeInternal`") and what probe 1.6 measured on the server: three redeliveries in
      25 s. A handler that raises on invalid input without saying so retries for the whole operation
      budget. That trap is real, it is the same everywhere, and it is **documented rather than
      diverged from** — a handler written here behaves like its counterparts, which is the point of
      an interoperability protocol.

      **A contradiction worth recording**, found while reading the source: the SDK's own comment says
      "`HandlerErrorTypeInternal` is not retryable by default" while its `Retryable()` classifies it
      as retryable. The code and the measurement agree; the comment is wrong. Follow what executes.

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
