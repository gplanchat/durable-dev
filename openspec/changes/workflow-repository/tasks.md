# Tasks

TDD throughout (WA002): the failing test first, in the same commit. Each commit under 200 changed
lines. Every behaviour is tested on the four backends through the shared conformance suites, not
once per backend by hand.

## 0. Decide and probe before building

- [ ] 0.1 Draft the ADR: one client API on every backend and host, the repository and its two
      forms, the start-option table. The ADR is the owner's decision (DUR000).
- [ ] 0.2 Probe Temporal: a query on a closed workflow; an update sent while the workflow
      completes; cancel and terminate through the existing gRPC methods; on Server 1.32 (CI) and
      1.25.2 (dev server). Also: the gRPC code and message of a start on a running workflow id
      and of a start the id reuse policy refuses; the update id in the `meta` of
      `WORKFLOW_EXECUTION_UPDATE_COMPLETED`.
- [ ] 0.3 Confirm `Container::whenHasAttribute()` on Laravel 11, the lowest supported line.
- [ ] 0.4 Confirm the `#[RepositoryFor]` compiler pass approach on Symfony 6.4.

## 1. Core

- [ ] 1.1 `WorkflowClientPort`, keyed by `ExecutionId`, and a conformance test case every backend
      subclasses: start, signal, query, update with result, result with bound, cancel, terminate,
      unknown execution, wrong workflow type.
- [ ] 1.2 `WorkflowRepository`, `#[AsWorkflowRepository]`, `create()` and `get()`.
- [ ] 1.3 `WorkflowStub`: `start()`, `execute()`, signal, query and update methods resolved from
      the workflow's attributes, `result()`, `cancel()`, `terminate()`, `executionId()`.
- [ ] 1.4 `#[RepositoryFor]`, the plain attribute.
- [ ] 1.5 The reserved-name check, shared by the hosts: a workflow whose signal, query or update
      method is named `start`, `execute`, `result`, `cancel`, `terminate` or `executionId`, in any
      case, is refused with an exception naming the class and the method.
- [ ] 1.6 `WorkflowUpdateHandled` gains a nullable update id, carried by `PendingUpdate`, the
      `$pendingUpdates` shape of `dispatchResume()` and `ResumeWorkflowMessage`,
      `recordUpdateHandled()` and `EventDataMapper`; `DeliverWorkflowUpdateHandler` passes the
      message's `updateId` on. Test: an event written without an id replays unchanged, and the
      client never matches it.
- [ ] 1.7 The stub follows a continue-as-new chain for `result()`, `cancel()` and `terminate()`;
      a `WorkflowContinuedAsNew` without `newExecutionId` (before #322) fails with a named
      exception instead of waiting.

## 2. Journal backends (in-memory, DBAL, Illuminate)

- [ ] 2.1 Signal and update through the port, the update's result read from the
      `WorkflowUpdateHandled` that carries the caller's update id. Test: two identical updates at
      once, each caller gets its own result.
- [ ] 2.2 The read-only query pass. Test: a query answers the state the live execution holds, and
      the journal has no new event afterwards.
- [ ] 2.3 Result with a bound; a failed execution rethrows its failure.
- [ ] 2.4 Cancel, then Saga compensation in the workflow, on the journal.
- [ ] 2.5 Terminate: the `terminated` kind, and no workflow code runs afterwards.
- [ ] 2.6 Start options: id reuse and timeouts honoured; task timeout, task queue, search
      attributes and cron refused at `start()` with a named exception.
- [ ] 2.7 The port's start on DBAL and Illuminate inserts the metadata row; a duplicate key is
      reported as an already started execution, and a start the reuse policy allows after an
      ended execution is an `UPDATE ... WHERE completed = true`. In memory, the map is checked
      first. The engine's `dispatchNewWorkflowRun()` and the continue-as-new path keep the upsert.
      Test: two concurrent starts of one id on DBAL and Illuminate, one wins, the first
      execution's input is unchanged.

## 3. Temporal

- [ ] 3.1 The port's Temporal implementation, `ExecutionId`-keyed, passing the same conformance
      test case against a real server.
- [ ] 3.2 Cancel and terminate.
- [ ] 3.3 An already started workflow fails the port's start. The swallowing moves from
      `doStartWorkflow()` to `startAsync()` and `startSync()`, which keep their behaviour; the
      engine's dispatcher keeps calling `startAsync()`.
- [ ] 3.4 `TemporalExecutionHistory` pairs `WORKFLOW_EXECUTION_UPDATE_COMPLETED` with its
      accepted update by id instead of taking the last one.

## 4. Hosts

- [ ] 4.1 Symfony (and Sylius through it): repositories autoconfigured from
      `#[AsWorkflowRepository]`, refused when their workflow is not registered; the
      `#[RepositoryFor]` compiler pass; the port bound on every backend.
- [ ] 4.2 Laravel: the port bound on every backend, signal and update delivery on the Illuminate
      queue, `whenHasAttribute(RepositoryFor::class)`.
- [ ] 4.3 Magento: a dispatcher and the port, from `RuntimeFactory` on both of its backends;
      declared repositories only.
- [ ] 4.4 Magento's in-memory backend: the stub's continue-as-new calls fail naming both ids
      while `InMemoryWorkflowRunner` does not start the next run; an issue is opened for the gap.
- [ ] 4.5 Each bench drives one workflow through a repository: start, signal, query, result.

## 5. PHPStan

- [ ] 5.1 `start()` and `execute()` typed from the entry method; signal, query and update methods
      typed from their declarations; a call to a method the workflow does not declare is an error.

## 6. Documentation, EN and FR

- [ ] 6.1 A page on starting and reaching a workflow: the repository first, `#[RepositoryFor]` on
      Symfony and Laravel, the Magento gap.
- [ ] 6.2 Correct every statement the audit listed: `backends` (the parity table and "fails
      explicitly"), `concepts`, `options`, `getting-started`, `packages`, `cancellation`.
- [ ] 6.3 The temporary start-option refusals, stated as such.
- [ ] 6.4 UPGRADE.md, at the end of "Unreleased": the client API keyed by `ExecutionId`
      instead of a Temporal workflow id (`signal()`, `query()`, `update()` on
      `WorkflowClientInterface`), with before and after code; a class that implements the
      interface migrates by hand; and the behaviour change: a second start through the client
      fails on every backend where it used to return (Temporal) or overwrite the first input
      (journal backends). That change has no Rector rule, since no call site changes.
- [ ] 6.5 A Rector rule for the re-keying, in `src/DurableRector`, added to the `durable-upgrade`
      set: it unwraps `$client->signal($client->workflowId($id), ...)`, and the same for
      `query()`, `update()`, into the `ExecutionId`; `WorkflowClient::workflowIdOf($string)`, which
      takes a string, becomes `ExecutionId::fromString($string)`. It
      does not wrap a bare string: a string that already holds a workflow id would be prefixed a
      second time. Bare strings are listed in UPGRADE.md for a hand migration.
- [ ] 6.6 A second model reviews the pages (CLAUDE.md, dispatch rule 3).

## 7. Close

- [ ] 7.1 `loop/guardrails/verify.sh` passes.
- [ ] 7.2 The options-parity change is opened for what this one refuses.
