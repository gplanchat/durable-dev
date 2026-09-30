# Tasks

TDD throughout (WA002): the failing test first, in the same commit. Each commit under 200 changed
lines. Every behaviour is tested on the four backends through the shared conformance suites, not
once per backend by hand.

## 0. Decide and probe before building

- [ ] 0.1 Draft the ADR: one client API on every backend and host, the repository and its two
      forms, the start-option table. The ADR is the owner's decision (DUR000).
- [ ] 0.2 Probe Temporal: a query on a closed workflow; an update sent while the workflow
      completes; cancel and terminate through the existing gRPC methods; on Server 1.32 (CI) and
      1.25.2 (dev server).
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

## 2. Journal backends (in-memory, DBAL, Illuminate)

- [ ] 2.1 Signal and update through the port, the update's result read from
      `WorkflowUpdateHandled`.
- [ ] 2.2 The read-only query pass. Test: a query answers the state the live execution holds, and
      the journal has no new event afterwards.
- [ ] 2.3 Result with a bound; a failed execution rethrows its failure.
- [ ] 2.4 Cancel, then Saga compensation in the workflow, on the journal.
- [ ] 2.5 Terminate: the `terminated` kind, and no workflow code runs afterwards.
- [ ] 2.6 Start options: id reuse and timeouts honoured; task timeout, task queue, search
      attributes and cron refused at `start()` with a named exception.

## 3. Temporal

- [ ] 3.1 The port's Temporal implementation, `ExecutionId`-keyed, passing the same conformance
      test case against a real server.
- [ ] 3.2 Cancel and terminate.

## 4. Hosts

- [ ] 4.1 Symfony (and Sylius through it): repositories autoconfigured from
      `#[AsWorkflowRepository]`, refused when their workflow is not registered; the
      `#[RepositoryFor]` compiler pass; the port bound on every backend.
- [ ] 4.2 Laravel: the port bound on every backend, signal and update delivery on the Illuminate
      queue, `whenHasAttribute(RepositoryFor::class)`.
- [ ] 4.3 Magento: a dispatcher and the port, from `RuntimeFactory` on both of its backends;
      declared repositories only.
- [ ] 4.4 Each bench drives one workflow through a repository: start, signal, query, result.

## 5. PHPStan

- [ ] 5.1 `start()` and `execute()` typed from the entry method; signal, query and update methods
      typed from their declarations; a call to a method the workflow does not declare is an error.

## 6. Documentation, EN and FR

- [ ] 6.1 A page on starting and reaching a workflow: the repository first, `#[RepositoryFor]` on
      Symfony and Laravel, the Magento gap.
- [ ] 6.2 Correct every statement the audit listed: `backends` (the parity table and "fails
      explicitly"), `concepts`, `options`, `getting-started`, `packages`, `cancellation`.
- [ ] 6.3 The temporary start-option refusals, stated as such.
- [ ] 6.4 A second model reviews the pages (CLAUDE.md, dispatch rule 3).

## 7. Close

- [ ] 7.1 `loop/guardrails/verify.sh` passes.
- [ ] 7.2 The options-parity change is opened for what this one refuses.
