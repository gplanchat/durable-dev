## 1. Probe the server before encoding any rule

- [ ] 1.1 Probe Nexus endpoint, service and operation name rules (empty, blank, edge whitespace, control characters, length, case) against a local dev server, as was done for `TaskQueue`, `WorkflowNamespace` and `CronSchedule` — **endpoint names done**: server enforces `^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$` and a 200-character limit, pinned by `NexusEndpointNameRulesTest`, verdicts in `design.md`. Service and operation names remain: they travel in the `ScheduleNexusOperation` command and need a workflow scheduling a real operation
- [ ] 1.2 Probe what the server does when scheduling on an unknown endpoint, and record the error shape
- [ ] 1.3 Probe whether the three operation bounds behave like the activity ones, including any silent rewrite
- [ ] 1.4 Record every verdict in the value-object docblocks, and write no invariant that was not observed

## 2. Domain value objects

- [ ] 2.1 `NexusEndpoint`, `NexusService`, `NexusOperationName` — named constructors, boundary coercion, validation limited to probed rules
- [ ] 2.2 `NexusOperationTimeouts` built on `Duration`, mirroring `ActivityTimeouts`, with `executionBoundOr()` if the server requires a closing bound
- [ ] 2.3 Unit tests asserting the probed verdicts, one case per observation

## 3. Caller-side domain plumbing

- [ ] 3.1 `NexusOperationAwaitable` carrying the operation identity, so the fiber driver can cancel it
- [ ] 3.2 `nexusOperationSlotIndex` in `ExecutionContext`, plus `scheduleNexusOperation()` on the environment
- [ ] 3.3 `findNexusOperationSlotResult()` and `findScheduledNexusOperation()` on `WorkflowHistorySourceInterface`
- [ ] 3.4 `scheduleNexusOperation()` and `cancelNexusOperation()` on `WorkflowCommandBufferInterface`
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
