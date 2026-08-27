# Tasks

## 1. Measure before deciding

- [ ] 1.1 Probe: deploy a workflow, let it suspend on an activity, change the code so a different
      activity occupies slot 0, and poll again. Record what the server does — nothing, a rejection,
      a workflow task failure.
- [ ] 1.2 Probe: fail a workflow task deliberately and observe the retry. Confirm the run survives
      and resumes when the code is put back.
- [ ] 1.3 Write both answers into `design.md`, replacing the assumption section.
- [ ] 1.4 Inventory what identity each scheduling event already carries — `ActivityScheduled`,
      `NexusOperationScheduled`, `ChildWorkflowScheduled`, `TimerStarted`. Any slot kind with no
      recorded identity is named as an uncovered gap, not fixed here.

## 2. The comparison, one slot kind at a time

- [ ] 2.1 RED: a replay test that swaps the activity at slot 0 between two polls of the same
      history, and expects a failure naming both names.
- [ ] 2.2 GREEN: identity accessor on `WorkflowHistorySourceInterface`, comparison in
      `ExecutionContext::activity()` before it resolves.
- [ ] 2.3 The same pair for `nexusOperation()` — the triple, not just the operation name.
- [ ] 2.4 The same pair for `childWorkflow()`.
- [ ] 2.5 Timers, if 1.4 found identity to compare. Otherwise a test that documents the gap.

## 3. The failure is legible

- [ ] 3.1 The exception carries execution id, slot kind, slot index, recorded identity, requested
      identity.
- [ ] 3.2 It surfaces as a failed workflow **task**, and a test asserts the run is still resumable
      after the code is restored.
- [ ] 3.3 The same guard fires identically on the DBAL backend — one parity test.

## 4. No regression on correct workflows

- [ ] 4.1 The full unit suite passes unchanged: an unmodified workflow compares equal at every slot.
- [ ] 4.2 The integration suite against a real server, green.
- [ ] 4.3 Measure the cost of the comparison on a long history; if it forces a second pass over the
      event stream, say so.

## 5. Say it in the documentation

- [ ] 5.1 A DUR recording the guard, its scope, and any slot kind left uncovered.
- [ ] 5.2 Correct DUR003's determinism section: it describes this guard as existing, and until this
      change lands it does not.
- [ ] 5.3 A user-facing note on what a divergence looks like and what to do about it — revert,
      or rename the workflow type.
- [ ] 5.4 Update the comparison page: the versioning row should say the failure is loud once this
      lands, since today it says nothing about how the gap fails.
