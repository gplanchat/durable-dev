# Tasks

## 1. Recover the work rather than rewrite it

- [x] 1.1 Establish that `aa89793` is an ancestor of `main` and that its file is not — the design
      was dropped by a merge resolution, not by a verdict.
- [x] 1.2 Re-run the caller inventory on today's `main`: no new caller of the three methods has
      appeared since.
- [x] 1.3 Cherry-pick `aa89793` and resolve the six conflicts (table in `design.md`).

## 2. The registry leaves the environment

- [x] 2.1 `QueryHandlerRegistry`, `@internal`, carried by `ExecutionContext`.
- [x] 2.2 `WorkflowFiberDriver` passes it as a second argument to the handler; one-parameter
      closures keep working, unmodified.
- [x] 2.3 `WorkflowDefinitionLoader` registers `#[QueryMethod]` handlers into it.
- [x] 2.4 `registerQueryHandler()`, `hasQueryHandler()` and `callQueryHandler()` leave
      `WorkflowEnvironment`.

## 3. The Temporal bridge reads from it

- [x] 3.1 `WorkflowTaskResult` carries the registry, keeping the protocol-messages field.
- [x] 3.2 `WorkflowTaskProcessor::handleQueries()` takes the registry.
- [x] 3.3 Verify both FAILED branches survive: unknown query and throwing handler — each has a test.
- [x] 3.4 Assert the query path changes nothing: the same task, polled with and without a query, produces the same commands.

## 4. Pin the decision

- [x] 4.1 A test asserting the three methods are off the surface.
- [x] 4.2 A test asserting `onSignal()` and `onUpdate()` stay on it — so the asymmetry reads as a
      decision.
- [x] 4.3 The bridge test that registered a query from a closure becomes a class with
      `#[QueryMethod]`.

## 5. Say it in the documentation

- [x] 5.1 DUR040.
- [x] 5.2 Amend DUR035: the symmetry it drew holds for signals and updates, not for queries.
- [x] 5.3 DUR034 loses `registerQueryHandler()` from its deferral list.
- [x] 5.4 DUR039 gains a forward pointer from its "Not decided here".
- [x] 5.5 `documentation/user/workflows/` stops offering an imperative form for queries, and says
      what that costs a closure-shaped workflow.
- [x] 5.6 `documentation/INDEX.md`.

## 6. Verify

- [x] 6.1 Unit suite green (528 tests).
- [x] 6.2 PHPStan clean.
- [x] 6.3 `php-cs-fixer` clean.
- [x] 6.4 Integration suite against a real Temporal server. **Green.** A live server was available
      (`temporal server start-dev`, `127.0.0.1:7233`, namespace `default`); the suite was run from a
      worktree whose `symfony/vendor/gplanchat/*` symlink onto `src/`, with the CI job's own command
      (`php bin/phpunit --testdox --display-skipped tests/Integration/Temporal/`) and its DSN:
      `Tests: 7, Assertions: 14, Skipped: 1`. The counts reconcile as `13 + 1 - 7`: #161 added
      `TemporalDashboardTimelineGroupingTest` by dropping the `--group` filters, and seven methods
      were removed here — the six of 7.1 plus the round-trip that moved to `tests/unit`.
      The suite does reach the server: `NativeExecutionSpikeIntegrationTest` drives a **fresh**
      execution (`durable-native-spike-test-<uuid>`) and polls its history until
      `WORKFLOW_EXECUTION_COMPLETED` with at least eight events, so it cannot be reading state left
      by an earlier run. The remaining five are DI-wiring and a single RPC. The eight errors first
      seen here had two causes, neither of them this change's; all three findings of §7 are now
      repaired. Nothing in them ever touched the query registry.

## 7. Found while running 6.4 — not tasks of this change

These are findings, not work items: they are recorded here because 6.4 is where they surfaced, and
they are why 6.4 stayed open so long. All three are now repaired.

**7.1 — repaired.** Not a rename: work that was never merged. Five classes were missing, not one —
`TemporalStartingEventStore`, `TemporalWorkflowStarter`, `TemporalNativeBootstrap`,
`JournalTemporalHistoryReader`, `JournalActivityInterpreter` — none in `src/`, on any remote branch,
or anywhere in the history (`git log --all -S`). They describe a **native bootstrap through the
journal**, where `append(new ExecutionStarted(...))` itself calls `StartWorkflowExecution` and the
pre-scheduled activity travels in a memo. No class today has that shape:
`TemporalJournalEventStore` takes `(client, connection)`, `TemporalReadThroughEventStore` takes
`(store, cursor, workflowClient)`. Only `JournalExecutionIdResolver` survived — and `WorkflowClient`
carries the trace, "Replaces TemporalWorkflowStarter": the starting was rebuilt elsewhere, the
journal bootstrap did not follow.

The six tests were **deleted**, their six claims recorded in the commit message for whoever revives
the subject. The one assertion among them that bore on living code — the
`TemporalActivityScheduleInput` round-trip, which needed no server and had never run because it sat
behind `temporal-integration` — moved to `tests/unit`, where it does.
`JournalExecutionIdResolver::MEMO_KEY_JOURNAL_BOOTSTRAP` is now read nowhere; it is left in place,
removing it falls outside this change.

**7.2 — repaired.** Two call sites left behind by `WorkflowNamespace`: `TemporalConnection::$namespace` is a
value object, and two integration tests still hand it where a string is required:
`NativeExecutionSpikeIntegrationTest:60` (`HistoryPageMerger::__construct()`, a `TypeError`) and
`WorkflowServiceExecutionRpcIntegrationTest:78` (protobuf's `setNamespace()`,
`InvalidArgumentException: Expect string`). The remaining **two** errors; both tests now pass
against a live server.

**7.3 — repaired.** The CI job was green while testing nothing: "Tests d'intégration Temporal (gRPC + Temporal
auto-setup)" reports `Tests: 13, Assertions: 3, Skipped: 10` in **45 ms**: every server-touching
test skips at `setUpBeforeClass` before a single RPC, leaving only the DI-wiring kernel tests. The
gate had reported success through 7.1 and 7.2 for as long as they have existed. The cause was a port:
tracked `symfony/.env` publishes the frontend on `7234`, to sit beside a local Temporal on `7233`,
while the job's DSN aimed at `7233`. The job now pins `TEMPORAL_FRONTEND_PORT`, waits for the
facade before running, and drops the `--group` filters — which had been excluding
`TemporalDashboardTimelineGroupingTest`, a test that carries no group and therefore ran nowhere.
The job was then **red** on 7.1 alone; with 7.1 repaired it is green. It is not a required check on `main`.
