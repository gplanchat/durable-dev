# Tasks

## 1. Probe before designing anything

- [ ] 1.1 Serve an operation with an official SDK (Go), call it from a Durable workflow, and dump
      every message on the wire. This is the reference trace the rest of the work is checked against.
- [ ] 1.2 `PollNexusTaskQueue` from the bridge: long-poll semantics, timeout, empty response.
- [ ] 1.3 `RespondNexusTaskCompleted` with a synchronous result — payload shape, what the caller
      receives.
- [ ] 1.4 The asynchronous shape: establish how an operation fulfilled by a workflow reports its
      result, and whether the handler is involved after the first response. Falsify or confirm the
      hypothesis in `design.md`.
- [ ] 1.5 Cancellation: does it reach a handler as a task, or only as state to poll for?
- [ ] 1.6 `RespondNexusTaskFailed`: which failures the server retries, and which are terminal.
- [ ] 1.7 A handler that never responds: what the server does, and how long it waits.
- [ ] 1.8 Rewrite `design.md` from what 1.1–1.7 found. If the asynchronous shape turned out much
      larger than assumed, propose the synchronous-only split here rather than pushing through.

## 2. The task worker

- [ ] 2.1 RED: a poll returning a task routes to a registered handler.
- [ ] 2.2 GREEN: poll loop, dispatch, `RespondNexusTaskCompleted` for the synchronous shape.
- [ ] 2.3 Failure path: a throwing handler responds `RespondNexusTaskFailed`, classified the way
      the caller side already classifies.
- [ ] 2.4 An operation nobody serves: the response says so, and the worker keeps polling.

## 3. The asynchronous shape

- [ ] 3.1 A handler that fulfils an operation with a workflow.
- [ ] 3.2 The caller receives that workflow's result as the operation's result.
- [ ] 3.3 The workflow fails: the caller sees an operation failure, classified.

## 4. Cancellation

- [ ] 4.1 A caller that cancels reaches the handler, in whatever form 1.5 established.
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
