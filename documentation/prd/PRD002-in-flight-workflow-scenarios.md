# PRD002 — “In-flight” workflow scenarios (distributed tests)

## Context

Functional tests in `WorkflowLaunchWithActivitiesTest` validate the **final** log after a full `InMemoryWorkflowRunner::run()`. For the simulated distributed runtime, **intermediate states** were missing explicit coverage: activity queue, resume after `ActivityCompleted`, completion after the last activity.

## Expected behavior

1. **After suspension** on the first activity `await`: the queue holds the expected messages (name + domain payload); the log contains `ExecutionStarted` and the matching `ActivityScheduled` entries, without `ActivityCompleted` for those waits.
2. **After `drain` of one activity**: the queue empties (or updates); the log includes the expected `ActivityCompleted`.
3. **After `resume`**: the workflow replays from history and enqueues the next activity if business code continues; repeat until no suspension.
4. **Final resume**: no queued message for that step; `ExecutionCompleted` with the final result; log aligned with the end-to-end reference scenario.

## Implementation (summary)

| Piece | Role |
|--------|------|
| `InMemoryActivityTransport::inspectPendingActivities()` / `pendingCount()` | Non-destructive FIFO snapshot for assertions |
| `StepwiseWorkflowHarness` | `start` / `resume` / `drainOneQueuedActivity` around `ExecutionEngine` + `ExecutionRuntime` (`distributed` mode) |
| `DistributedWorkflowExpectedJournal::*After*` | Expected logs per stage |
| `DurableTestCase::assertActivityTransportPendingEquals()` | PHPUnit assertion on the queue |
| `WorkflowStepwiseDistributedExecutionTest` | Greet + three doubles chained scenarios |

## Acceptance criteria

- [x] At least one single-activity scenario with queue + log + final result checks.
- [x] At least one multi-activity sequential scenario with checks at each suspend / drain / resume.
- [x] **`all()`** scenarios: three activities scheduled before the first suspend (queue size 3), then FIFO drains until aggregated result.
- [x] **`any()`** scenarios: two activities queued; one drain is enough for resume to finish the workflow; the second may remain queued (single drain vs `runUntilIdle`).
- [x] Final log remains equivalent to existing scenarios (`DistributedWorkflowJournalEquivalentConstraint`).

## References

- `tests/functional/Bridge/WorkflowStepwiseDistributedExecutionTest.php`
- `tests/Support/StepwiseWorkflowHarness.php`
- ADR009 — distributed model
