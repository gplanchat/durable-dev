## 1. Establish the history rule before writing the helper

- [ ] 1.1 Check, on both backends, that a fired deadline timer and a signal delivered afterwards are ordered against each other in recorded history, and record how that order is read
- [ ] 1.2 Probe whether cancelling an in-flight activity after a deadline stops the attempt server-side or merely detaches it, and record the verdict for the docblock
- [ ] 1.3 Decide option 1 (order-aware lookup) or option 2 (journaled abandonment) from 1.1, and note the verdict in `design.md`

## 2. Failing tests first

- [ ] 2.1 A deadline that elapses raises a timeout failure, and the failure is catchable on its own
- [ ] 2.2 Work returning `null` before its deadline returns `null` — the case the current race cannot express
- [ ] 2.3 Replay of a timed-out execution reaches the same verdict and schedules nothing new
- [ ] 2.4 A signal delivered after its deadline elapsed does not undo the timeout on replay
- [ ] 2.5 A signal delivered after a wait timed out is still observed by a later wait for the same name
- [ ] 2.6 The bounded activity is cancelled when the deadline elapses; a settled result cancels the deadline timer
- [ ] 2.7 A signal wait without a deadline still waits indefinitely

## 3. Domain

- [ ] 3.1 `WorkflowTimeoutException` carrying the elapsed deadline and what was being awaited, classified like the other workflow failures
- [ ] 3.2 Deadline-aware await on `WorkflowEnvironment`, built on the existing timer and race composition, holding the timer it created so the verdict never depends on branch identity leaking out of `any()`
- [ ] 3.3 Optional deadline on `waitSignal()`, defaulting to the current unbounded behaviour
- [ ] 3.4 The history rule from 1.3, so a wait that timed out is not settled by a later event

## 4. Backend parity

- [ ] 4.1 Same workflow, same verdict, in-memory and Temporal — including the replay path
- [ ] 4.2 Integration test against a real server for the signal-after-deadline case, the one that cannot be trusted to a fake

## 5. Documentation

- [ ] 5.1 Rewrite the race-with-timer example in `documentation/user/workflows/_index.md`: a deadline is a deadline, and `any()` goes back to being composition
- [ ] 5.2 Say plainly, where activity bounds are documented, which timeout is enforced by the backend and which is workflow-side
- [ ] 5.3 ADR DUR032: why a timeout is a failure rather than a null, and why a signal verdict must come from history order
