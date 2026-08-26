## 1. Establish the history rule before writing the helper

- [x] 1.1 Check, on both backends, that a fired deadline timer and a signal delivered afterwards are ordered against each other in recorded history, and record how that order is read — in-memory: one ordered stream; Temporal: `eventId`, now recorded for `TIMER_FIRED` and `WORKFLOW_EXECUTION_SIGNALED`
- [x] 1.2 Probe whether cancelling an in-flight activity after a deadline stops the attempt server-side or merely detaches it, and record the verdict for the docblock — it detaches: `RequestCancelActivityTask` is a request (see `ActivityCancellationType`)
- [x] 1.3 Decide option 1 (order-aware lookup) or option 2 (journaled abandonment) from 1.1, and note the verdict in `design.md` — option 1

## 2. Failing tests first

- [x] 2.1 A deadline that elapses raises a timeout failure, and the failure is catchable on its own
- [x] 2.2 Work returning `null` before its deadline returns `null` — the case the current race cannot express
- [x] 2.3 Replay of a timed-out execution reaches the same verdict and schedules nothing new
- [x] 2.4 A signal delivered after its deadline elapsed does not undo the timeout on replay — plus its sibling: a signal recorded *before* the timer fired still settles the wait, which is what proves the verdict does not come from branch declaration order
- [x] 2.5 A signal delivered after a wait timed out is still observed by a later wait for the same name
- [x] 2.6 The bounded activity is cancelled when the deadline elapses; a settled result cancels the deadline timer
- [x] 2.7 A signal wait without a deadline still waits indefinitely — already covered by `HarnessParityTest::testRunnerReportsAWorkflowItCannotAdvanceInsteadOfLooping`

## 3. Domain

- [x] 3.1 `DeadlineExceededException` carrying the elapsed deadline and what was being awaited, classified as `WorkflowExecutionFailed::KIND_DEADLINE_EXCEEDED` — named for the deadline, not `WorkflowTimeoutException`, which would have collided with the `WorkflowTimeouts` value object
- [x] 3.2 Deadline-aware await on `WorkflowEnvironment`, built on the existing timer and race composition, holding the timer it created and reading the verdict back from the branches rather than from `any()`'s winning value
- [x] 3.3 Optional deadline on `waitSignal()`, defaulting to the current unbounded behaviour
- [x] 3.4 The history rule from 1.3, so a wait that timed out is not settled by a later event — plus the slot release for the abandoned wait, and per-name signal slots in the in-memory backend so both backends mean the same thing by "slot"

## 4. Backend parity

- [x] 4.1 Same workflow, same verdict, in-memory and Temporal — including the replay path (`WorkflowDeadlineTest`, `WorkflowTaskRunnerTest`)
- [x] 4.2 Integration test against a real server for the signal-after-deadline case, the one that cannot be trusted to a fake (`tests/integration/Temporal/WorkflowDeadlineTest.php`)

## 5. Documentation

- [x] 5.1 Rewrite the race-with-timer example in `documentation/user/workflows/_index.md`: a deadline is a deadline, and `any()` goes back to being composition
- [x] 5.2 Say plainly, where activity bounds are documented, which timeout is enforced by the backend and which is workflow-side
- [x] 5.3 ADR DUR032: why a timeout is a failure rather than a null, and why a signal verdict must come from history order
