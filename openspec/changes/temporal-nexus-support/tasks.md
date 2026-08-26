## 1. Probe the server before encoding any rule

- [x] 1.1 Probe Nexus endpoint, service and operation name rules (empty, blank, edge whitespace, control characters, length, case) against a local dev server, as was done for `TaskQueue`, `WorkflowNamespace` and `CronSchedule` — **endpoint**: server-enforced `^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$`, 200 chars, refused at creation. **service and operation**: the server validates neither and records them verbatim, so they need `TaskQueue`-style strictness while the endpoint needs none. Pinned by `NexusEndpointNameRulesTest` and `NexusServiceAndOperationNameRulesTest`; the three rules are tabulated in `design.md`
- [x] 1.2 Probe what the server does when scheduling on an unknown endpoint, and record the error shape — gRPC `INVALID_ARGUMENT`, `BadScheduleNexusOperationAttributes: endpoint "…" not found`, `WORKFLOW_TASK_FAILED` with cause `BAD_SCHEDULE_NEXUS_OPERATION_ATTRIBUTES`, and the task is retried without end. No typed failure reaches the workflow; pinned by `NexusUnknownEndpointTest`, consequence recorded in `design.md`
- [x] 1.3 Probe whether the three operation bounds behave like the activity ones, including any silent rewrite — **yes, and the rewrite exists**: a sub-bound larger than `scheduleToClose` is clamped down to it without an error; a negative duration is refused with the field named; `scheduleToClose = 0` means unbounded and clamps nothing; omitted bounds stay absent. Pinned by `NexusOperationBoundsTest`, table in `design.md`
- [x] 1.4 Record every verdict in the value-object docblocks, and write no invariant that was not observed — les trois objets portent leurs verdicts : `NexusEndpoint` la regex et la limite du serveur, `NexusService` et `NexusOperationName` le fait qu'il n'en valide aucun, `NexusOperationTimeouts` le rabot silencieux. Aucune borne de longueur ni alphabet n'est imposé aux deux noms : le serveur n'en a montré aucun

## 2. Domain value objects

- [x] 2.1 `NexusEndpoint`, `NexusService`, `NexusOperationName` — named constructors, boundary coercion, validation limited to probed rules — **`NexusEndpoint` fait** : motif et limite du serveur, ni plus ni moins, avec la distinction vide/malformé qu'il fait lui-même. `NexusService` et `NexusOperationName` attendent la moitié service/opération de 1.1 ; `NexusService` et `NexusOperationName` **faits** : plus stricts que le serveur comme `TaskQueue`, puisqu'il n'en valide aucun et que la faute y est muette
- [x] 2.2 `NexusOperationTimeouts` built on `Duration`, mirroring `ActivityTimeouts`, with `executionBoundOr()` if the server requires a closing bound — **no `executionBoundOr()`**: §1.3 measured that the server requires no closing bound (a command with none of the three is accepted and records none), so the condition does not hold. The object is stricter than the server on the one thing the server does silently: a sub-bound exceeding `schedule-to-close` is refused at construction instead of being clamped without a word. No heartbeat bound — a Nexus operation is served elsewhere and gives no intermediate sign of life
- [x] 2.3 Unit tests asserting the probed verdicts, one case per observation — **fait pour `NexusEndpoint`** : 20 cas, un par verdict du tableau de `design.md`, y compris la lettre seule que le motif refuse ; 26 cas de plus pour `NexusService` et `NexusOperationName`, refus **et** acceptations — un nom à point ou à barre oblique doit rester valide

## 3. Caller-side domain plumbing

- [x] 3.1 `NexusOperationAwaitable` carrying the operation identity, so the fiber driver can cancel it — même forme qu'`ActivityAwaitable`, `inner()` compris parce que `AwaitableCancellation` et les composites descendent par lui. L'annulation elle-même reste à câbler : elle a besoin du `cancelScheduledNexusOperation()` de §3.2
- [x] 3.2 `nexusOperationSlotIndex` in `ExecutionContext`, plus `scheduleNexusOperation()` on the environment — même forme de replay qu'une activité, et `pendingNexusOperations()` retient l'attente pour que §3.5 et §4.3 puissent la régler
- [x] 3.3 `findNexusOperationSlotResult()` and `findScheduledNexusOperation()` on `WorkflowHistorySourceInterface` — le backend journal rend toujours null parce qu'il refuse de planifier ; le backend Temporal aussi, en attendant la lecture des neuf événements de §4.3, ce que son docblock dit
- [x] 3.4 `scheduleNexusOperation()` and `cancelNexusOperation()` on `WorkflowCommandBufferInterface` — le backend journal **refuse** avec `NexusUnsupportedByBackendException`, ce que la proposition exige ; le tampon Temporal lève un `LogicException` nommant §4.1 / §4.2, pas l'exception « backend sans route » qui serait un mensonge sur ce que Temporal sait faire
- [ ] 3.5 Extend `WorkflowFiberDriver::cancelPending()` to cancel a pending Nexus operation on workflow cancellation
- [ ] 3.6 `DurableNexusOperationFailedException` with its four kinds, and its classification in `WorkflowFailureClassifier`

## 4. Temporal backend

- [ ] 4.1 Build `ScheduleNexusOperation` in `TemporalWorkflowCommandBuffer`, bounds and headers included
- [ ] 4.2 Build `RequestCancelNexusOperation` using the real scheduled event id read from history
- [ ] 4.3 Read the nine `NEXUS_OPERATION_*` events in `TemporalExecutionHistory`, keyed by scheduled event id
- [ ] 4.4 Convert those events in `TemporalEventConverter` so the profiler and the read-through store show them
- [ ] 4.5 Fail clearly when an operation reports `NEXUS_OPERATION_STARTED` with a token — asynchronous operations are out of scope for this increment

## 5. In-memory backend

- [ ] 5.1 `EventStoreCommandBuffer::scheduleNexusOperation()` throws, naming the limitation and pointing at the Temporal backend
- [ ] 5.2 A test asserting the harness fails fast rather than hanging

## 6. Integration against a real server

- [ ] 6.1 Document the endpoint prerequisite at the top of the test, as the search-attribute suite documents its two attributes
- [ ] 6.2 Schedule an operation and assert the round trip through history
- [ ] 6.3 Assert cancellation reaches the server with the real scheduled event id
- [ ] 6.4 Assert a failed operation surfaces to the workflow with its origin named

## 7. Documentation

- [ ] 7.1 ADR recording the caller-only scope, the backend asymmetry, and why the handler side is a separate change
- [ ] 7.2 Update `documentation/INDEX.md`
