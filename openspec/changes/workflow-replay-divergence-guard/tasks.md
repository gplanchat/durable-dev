# Tasks

## 1. Measure before deciding

- [x] 1.1 Probe: deploy a workflow, let it suspend on an activity, change the code so a different
      activity occupies slot 0, and poll again. **The server does nothing.** The run completed
      successfully carrying the previous activity's result.
- [x] 1.2 Probe: fail a workflow task deliberately and observe the retry. **Refuted.** Raising from
      workflow code emits a FailWorkflowExecution command; the run dies and restoring the code does
      not resume it. The bridge never calls `RespondWorkflowTaskFailed`.
- [x] 1.3 Both answers written into `design.md`, replacing the assumption section.
- [x] 1.4 Identity inventory done: `ActivityScheduled` carries `activityName`,
      `NexusOperationScheduled` the endpoint/service/operation triple, `ChildWorkflowScheduled` the
      child type. **`TimerScheduled` carries none** — `scheduledAt` is an absolute due date and
      `summary` is optional. Timers are an uncovered gap.

## 2. The comparison, one slot kind at a time

- [x] 2.1 **Prerequisite, found by the probe.** The bridge responds `RespondWorkflowTaskFailed`
      when `WorkflowTaskFailure` escapes workflow code, instead of emitting a failure command.
      Verified against a real server: `WORKFLOW_TASK_FAILED` appears, the run stays alive, and
      restoring the code that wrote the history lets it complete normally. Any other throwable
      still fails the execution — the distinction is the whole point.
      **Not covered:** the backends with no notion of a task. There, `WorkflowTaskFailure` behaves
      like any other exception; 2.3–2.6 will say whether that is acceptable for the guard.
- [x] 2.2 RED: a replay test that swaps the activity at slot 0 between two polls of the same
      history, and expects a failure naming both names.
- [x] 2.3 GREEN: `activityNameForSlot()` on `WorkflowHistorySourceInterface`, implemented by both
      history sources, compared in `ExecutionContext::activity()` before it resolves. Verified
      against a real server: the scenario that used to end `WORKFLOW_EXECUTION_COMPLETED` carrying
      the other activity's result now ends `WORKFLOW_TASK_FAILED` with the run still alive.
      **Rule found while doing it:** an empty recorded name means *nothing recorded*, not a
      divergence. Both adapters normalise it to null, and the port promises never to answer an
      empty string. Without that rule the guard fired on every slot whose journal entry carries no
      name — three existing tests said so.
- [x] 2.4 The same pair for `nexusOperation()` — the **triple**, not just the operation name.
      Tested against `TemporalExecutionHistory` rather than the journal backend: that backend
      refuses Nexus by construction (DUR036), so no journal of its can hold one and the guard would
      have nothing to compare. The journal source answers null for Nexus, and says why.
- [x] 2.5 The same pair for `childWorkflow()` — the workflow **type**. The bridge did not index it
      at all; `childWorkflowTypes` now runs alongside `childExecutionIds`. The execution id is
      deliberately not compared: it is generated, so a faithful replay would diverge every time.
      The three call sites share one rule, `refuseDivergence()`.
- [x] 2.6 Timers: 1.4 found no identity to compare, and the gap is pinned rather than filled.
      `TimerSlotHasNoIdentityTest` holds three things: the behaviour as it is — a changed duration
      replays and nothing is reported; the reason — `scheduledAt` is an **absolute** instant, so two
      timers of different durations scheduled at different moments can carry the same one, and
      `summary` is optional; and what **bounds** the gap — a slot shift escapes the guard only if it
      touches timers alone, since any activity moving with it is caught by name.
      The third is the one that matters: without it, the gap reads as unbounded.

## 3. The failure is legible

- [ ] 3.1 The exception carries execution id, slot kind, slot index, recorded identity, requested
      identity.
- [ ] 3.2 It surfaces as a failed workflow **task** — which depends on 2.1 — and a test asserts the
      run is still resumable after the code is restored.
- [ ] 3.3 The same guard fires identically on the DBAL backend — one parity test.

## 4. No regression on correct workflows

- [ ] 4.1 The full unit suite passes unchanged: an unmodified workflow compares equal at every slot.
- [ ] 4.2 The integration suite against a real server, green.
- [ ] 4.3 Measure the cost of the comparison on a long history; if it forces a second pass over the
      event stream, say so.

## 5. Say it in the documentation

- [x] 5.1 **DUR042** records the guard: the identity compared per slot kind, why it rests only on
      what history already holds, why the failure is the task and not the run, and that timers are
      uncovered. Written before 5.2 rather than after — this repository amends an ADR *by* a
      decision, so correcting DUR003 needed something to point at.
- [x] 5.2 DUR003's determinism section corrected. Points 2 and 3 claimed a comparison and a
      non-determinism error that were never implemented; the section now states what the runner
      actually does and carries a `> Corrected by DUR042` note saying what was wrong and what the
      measurement showed.
      **Checked and deliberately left alone:** DUR035's "no non-determinism detection is promised"
      is about *conditions*, not slot identity. The two do not conflict.
- [ ] 5.3 A user-facing note on what a divergence looks like and what to do about it — revert,
      or rename the workflow type.
- [ ] 5.4 Update the comparison page: the versioning row should say the failure is loud once this
      lands, since today it says nothing about how the gap fails.
