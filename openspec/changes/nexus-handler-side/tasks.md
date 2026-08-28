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
      **Order, arbitrated after the fact:** section 3 leads. Section 2 lands the synchronous answer
      afterwards, as the special case of the same worker — the one where the handler returns a value
      instead of a token. Nothing was removed from section 2; only its position moved.
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

- [x] 2.1 **Routing exists, and it has no I/O.** `NexusOperationRegistry` declares a handler for a
      (service, operation) pair and dispatches a payload to it; `NexusOperationResponse` carries the
      two shapes; `NexusOperationNotHandledException` refuses an operation nobody serves, typed
      `NOT_IMPLEMENTED` — the **terminal** side of 1b.3's table, so an unserved operation is not
      re-asked every ~9 s for the whole operation budget.
      The response contract is `fulfilledByWorkflow()`, not `startedAsynchronously($token)`, on the
      probe's evidence — see §3.
      **Pulled forward out of order**, because 3.1 cannot exist without a dispatch surface. Section
      2's *synchronous response over gRPC* still lands after section 3, as 1b.1 arbitrated. The
      half of this task that says "a poll returning a task" belongs to the worker, 2.2.
      Spec note: the scenario *"and the component keeps serving its other operations"* is a poll-loop
      property and is **not covered** by this tranche.
- [x] 2.2 **`TemporalNexusWorker::pollOnce()`.** Poll, route through the registry, respond. An empty
      poll returns without a word (§1.2 — a success, not an error). The synchronous shape answers
      `syncSuccess`; the deferred one is 3.1 below, in the same method.
- [x] 2.3 **Failure path.** A handler that raises answers `RespondNexusTaskFailed` typed `INTERNAL`
      with `RETRYABLE` — what every other SDK does with an ordinary exception, per 1b.3. A handler
      that wants a terminal refusal says so with its own type.
- [x] 2.4 **An operation nobody serves.** Answered `NOT_IMPLEMENTED` / `NON_RETRYABLE` — the
      terminal side of 1b.3, so it is not re-asked every ~9 s for the whole operation budget. The
      server accepts that refusal: an integration test sends it against a real server, where a
      malformed one would be rejected. A task variant this worker does not serve yet (cancellation,
      §4) is refused the same way rather than left to expire in silence.

## 3. The asynchronous shape — both sides

- [x] 3.1 **A handler that fulfils an operation with a workflow.** Measured first, then built.
      `NexusAsynchronousFulfilmentTest` proved against a real server that a workflow started with
      the task's `callback` in `completion_callbacks` completes the caller's operation with its own
      result, and that removing that attachment — nothing else — leaves the caller at
      `NEXUS_OPERATION_STARTED` forever. The worker now does it: it reads `callback` and
      `callbackHeader` off the start task, attaches them to the workflow it starts, and only then
      answers `asyncSuccess`. The order is not cosmetic — `completion_callbacks` can only be set at
      start, so answering first would leave the caller waiting on an outcome nobody would send.

- [x] 3.2 **The caller receives that workflow's result.** `NexusServedOperationTest` runs the whole
      path through `TemporalNexusWorker` — the production code, not a reconstruction — against a
      real server: the caller's history ends on `NEXUS_OPERATION_COMPLETED` carrying what the
      fulfilling workflow returned. Mutated (the callback attachment removed), the same test fails
      with the caller stuck at `1, 5, 6, 7, 48, 49`.
- [ ] 3.3 The workflow fails: the caller sees an operation failure, classified.
- [x] 3.4 **Caller side, found by the probe. Done.** The caller refused an async response: on
      `NEXUS_OPERATION_STARTED` carrying a token it recorded an *outcome*, and that outcome was a
      failure — so a workflow died on an operation that was going to answer.
      The refusal was removed, not replaced. Probe 1.4 measured that the server posts
      `callback: temporal://system` and correlates the outcome itself onto the calling execution by
      `scheduledEventId` — the key the COMPLETED / FAILED / TIMED_OUT / CANCELED branches already
      read. `findNexusOperationSlotResult()` returns `null` when there is no entry, and `null` is
      exactly "still in flight", so the branch now records nothing at all.
      **BREAKING:** `NexusAsynchronousOperationUnsupportedException` is deleted; nothing raises it.
      Proven by unit tests over synthesised histories: started-with-token stays pending, a later
      completion resolves it, a later failure classifies like any other. **Not yet observed end to
      end from PHP** — that needs a handler that answers with a token, which is 3.1. This task is
      the enabler, and its own text said so.

## 4. Cancellation

- [x] 4.1 **A caller that cancels reaches the handler — measured.** 1.5 had only the negative half:
      with the start task still pending, cancelling the caller wrote `NEXUS_OPERATION_CANCEL_REQUESTED`
      and **no handler task arrived**, the operation never having started. The positive half could
      not be observed until an operation could start asynchronously. It can now:
      `NexusServedCancellationTest` shows a `cancel_operation` task arriving for a started
      operation, and **naming the operation token returned at start**.
- [x] 4.2 **A handler observes the cancellation — through the workflow, not a hook.** The token this
      worker returns *is* the workflow it started, so the cancellation task hands back exactly the
      handle needed: the worker cancels that workflow and acknowledges the task. The handler
      function is not called again, and that is not a gap — what carries the operation is a
      workflow, and a workflow already observes its own cancellation, with its compensations. A
      handler-side hook would duplicate that path without adding to it.
      A cancellation carrying no token is refused `BAD_REQUEST`, terminal.
- [x] 4.3 **Cancelling an operation already fulfilled asynchronously.** The workflow carrying it is
      cancel-requested; the end-to-end test asserts `WORKFLOW_EXECUTION_CANCEL_REQUESTED` lands on
      it. If that workflow finished between the caller's request and the worker's poll, the
      cancellation is **still acknowledged** rather than failed: the operation is already settled,
      and answering with an error would have the task re-delivered every ~9 s for nothing.

## 5. Registration and refusal

- [x] 5.1 **Declaring a served operation.** `#[AsNexusOperationHandler(service: …, operation: …)]`
      on a service, autoconfigured into the `durable.nexus_handler` tag, read by `NexusHandlerPass`
      and registered on `NexusOperationRegistry`. Both names are validated at compile time, not on
      the first task: a typo in either produces a handler nothing ever reaches, and the server has
      nothing to complain about.
- [x] 5.2 **A worker for the Nexus task queue** — a Messenger transport, `purpose=nexus_worker`,
      exactly as the activity worker is. `messenger:consume` already knows how to hold a loop,
      restart it, bound it in time and supervise it; a dedicated console command would say all of
      that again, less well. The queue comes from the connection: `nexus_task_queue` in the DSN,
      **defaulting to the workflow task queue** rather than to a name of its own — a Nexus endpoint
      targets a queue, and a default queue nobody polls is an endpoint that never answers, silently.
- [x] 5.3 **Refusal at startup, naming what is missing — in two places, deliberately.** `NexusHandlerPass` throws at container
      compile time when a handler is declared and `durable.temporal.nexus_registry` is absent — the
      service the Temporal backend registers as soon as a DSN is configured. The message names the
      backend, the missing key, and the services that declared a handler.
      This is the asymmetry the design called out: the caller refuses at call time because that is
      when the mistake shows, while a handler with no route is not a call that fails but a service
      that never receives anything. There is no request to fail later.
      A container with no handler at all is left alone — refusing there would break every
      application that does not use Nexus.
      **And the core refuses too.** `NexusOperationRegistry` is built through `routedBy()` or
      `unavailableOn()`, and the second throws on `register()`. The compiler pass catches only
      Symfony; the Magento module and the Illuminate bridge wire their services otherwise and would
      have had nothing — the very hosts whose users would meet the silence in production. The two
      complement rather than duplicate: the pass fails **earlier** and names the offending services,
      which a registry cannot do; the registry catches every host the pass never sees.

## 6. End to end

- [x] 6.1 Caller and handler in the same integration test, against a real server, both shapes —
      `NexusServedOperationTest`, three cases: immediate, deferred, and unserved.
- [ ] 6.2 Cross-check against the Go reference trace from 1.1: same messages, same order.
- [ ] 6.3 Full unit and integration suites green.

## 7. Say it in the documentation

- [x] 7.1 **DUR045 — Serving a Nexus operation: one worker, two shapes, and a refusal at startup.**
      Written from what was measured, including the three probes that overturned a hypothesis: the
      two budgets, what actually correlates a deferred answer, and the cancellation form.
- [x] 7.2 **DUR036 corrected.** A superseded-on-one-point banner at the top, a forward pointer where
      it said "serving is a separate change" — that change landed, and the split it predicted held.
      Its backend asymmetry and value-object rules stand unchanged. The INDEX row is retitled so the
      table does not keep announcing a caller-only component.
- [x] 7.3 **A user page**, `documentation/user/nexus/`, in both languages. Calling and serving on one
      page, because a serving-only page with no caller context would read oddly. The nine-second
      budget is stated as the thing that decides which response shape to use, since that is the one
      decision a handler author actually makes.
- [x] 7.4 **The comparison page**, both languages: "caller only" is gone, a serving example is in,
      and the section says what it now means that no other PHP implementation serves Nexus — a PHP
      team was reachable over HTTP but not through the boundary Temporal gives to Go, Java, Python,
      TypeScript and .NET.
      **Found on the way, and repaired:** the backends capability matrix still read
      `Nexus operations | ❌ | ❌ | ❌ (planned — caller side)` in both languages — stale twice over,
      the caller side having shipped with DUR036. And `OST004` still carried `nexus-handler-side`
      at 7/32 as "the largest single piece of unbuilt work in the repository".

**8.1 — 1b.2 left two integration tests behind, red on `main`.** Removing the
`{operationId, payload}` envelope changed two things these tests still asserted the old way:
`NexusOperationRoundTripTest::testTheInputSurvivesTheRoundTrip` looked for `$decoded['payload']` in
an input that is now the caller's bare payload, and
`NexusCancellationAndFailureTest::testCancellationReachesTheServerWithTheRealScheduledEventId`
cancelled by the application-level id passed at scheduling, when the identity is now the
`scheduledEventId` the server assigns — so the buffer found nothing and emitted no command.
Both repaired here. The whole Nexus integration suite is now green against a real server:
**47 tests, 193 assertions**. Neither test runs in CI, which is why they stayed red unnoticed —
the same blind spot 7.3 of `query-plumbing-leaves-the-environment` found on the Symfony side.

**8.2 — what the unit tests of the worker cannot prove.** They assert what the worker *sends*,
against a mocked gRPC client. Whether the server **accepts** it is a different question: a malformed
`syncSuccess` or a badly attached callback passes a mock assertion and is rejected on the wire. That
is why every branch of the worker also has an integration case. Said here so nobody reads the unit
suite as sufficient.

**8.3 — the served-cancellation test costs a minute.** `pollOnce()` is one poll and one poll only:
on an idle queue it returns after the long-poll deadline without a word (§1.2), and the cancellation
task does not always present itself on the first call. The test therefore loops, and pays the
long-poll wait. Worth knowing before this suite is ever added to CI — which, per 8.1, it is not.

## 9. Announce it

- [x] 9.1 **The home page says Nexus works.** A dedicated section between the capability stories and
      the package table, in both languages, with the two halves side by side — calling through a
      typed stub, serving through a contract. Plus an entry in the header nav and a highlighted chip
      in the capability list.
      The claim it makes is the measured one and no more: *a PHP service can now be on both ends of a
      Nexus operation*, where Temporal documents Nexus for Go, Java, Python, TypeScript and .NET and
      not for PHP. It ships in the same pull request as the feature, so the page cannot announce
      something `main` does not have.
- [x] 9.2 **The site stops contradicting itself.** The authoring surface changed mid-change, and the
      user page, the comparison page and DUR045 still showed the string form. All three now show the
      typed contract, in both languages, and `documentation/user/` lists the Nexus page.
      DUR045 is rewritten rather than annotated: it records a decision that has **not shipped yet**,
      so leaving a superseded contract in it would document a design nobody chose.
