## 0. Unblock

- [ ] 0.1 `workflow-side-deadlines` landed and archived, so its requirements are a published spec this change can be measured against
- [ ] 0.2 The in-flight awaitable refactor (composite / quorum) committed — `WorkflowEnvironment` is the single entry point both changes touch

## 1. Establish the evaluation point before writing the primitive

- [ ] 1.1 Decide and write down, in journal-position terms, when a pending condition is re-evaluated, and record it in `design.md`
- [ ] 1.2 Check whether a Temporal workflow task can carry several journaled messages at once, so "handlers run in journal order" is an ordering question inside one task and not only across tasks
- [ ] 1.3 Probe, against the running server, how a worker accepts and completes an update — which messages carry the acceptance and the response, and on which task they are returned. Nothing about update responses reaches the domain before this is seen
- [ ] 1.4 Record what the probes changed, if anything, in `design.md`

## 2. Failing tests first — conditions

- [ ] 2.1 A condition that already holds does not suspend, and records nothing that would wake the execution
- [ ] 2.2 A condition becomes true on a journaled input and the workflow resumes there
- [ ] 2.3 Replay resumes at the same journal position and takes the same path
- [ ] 2.4 A condition under a deadline that does not hold in time raises the timeout failure, and an input recorded afterwards does not undo it
- [ ] 2.5 A condition that can never hold is reported as unable to advance, naming the condition, instead of spinning
- [ ] 2.6 A condition whose outcome depends on something the journal does not record is reported as a divergence, not silently resolved

## 3. Failing tests first — dispatch

- [ ] 3.1 The declared handler receives the signal payload, and the state it mutates is visible to the body
- [ ] 3.2 A signal with no declared handler behaves exactly as today
- [ ] 3.3 Two signals are handled in recorded order, identically on every replay
- [ ] 3.4 The handler runs before the wait it feeds resolves, with the same payload
- [ ] 3.5 Three deliveries of one name feed three waits in recorded order, and the handler ran three times
- [ ] 3.6 A wait that gave up consumes nothing; the delivery reaches the handler and a later wait
- [ ] 3.7 An update handler's return value reaches the caller, and survives replay
- [ ] 3.8 A raising update handler fails the update, not the workflow

## 4. Domain — conditions

- [ ] 4.1 Await on a condition on `WorkflowEnvironment`, composing with the existing deadline
- [ ] 4.2 Condition evaluation staged by journal position, from 1.1
- [ ] 4.3 Divergence and never-true conditions reported through the existing "cannot advance" path rather than a new failure vocabulary

## 5. Domain — dispatch

- [ ] 5.1 `#[SignalMethod]` read at load time, alongside the existing `#[QueryMethod]` scan
- [ ] 5.2 Engine-side dispatch in journal order, handlers before the waits they feed
- [ ] 5.3 `waitSignal()` re-founded on the handler-fed buffer and the per-name counter, keeping `releaseSignalWaitSlot()`'s rule for a wait that gave up
- [ ] 5.4 `#[UpdateMethod]` dispatch with the handler's return value as the response, and `waitUpdate()` re-founded the same way
- [ ] 5.5 Worker-side update acceptance and completion on the Temporal bridge, from the probe in 1.3

## 6. Regression gate

- [ ] 6.1 The eleven tests of `WorkflowDeadlineTest` and the signal-after-deadline integration test stay green **without edits** — edits there mean the re-founding changed observable behaviour
- [ ] 6.2 Same workflow, same handlers, same order, in-memory and Temporal, including the replay path
- [ ] 6.3 Integration test against a real server for an update that answers

## 7. Documentation

- [ ] 7.1 Signals gain their handler form in `documentation/user/`, next to the explicit wait, with a plain statement of when to use which
- [ ] 7.2 Conditions documented with their determinism rule: a predicate reads workflow state and nothing else
- [ ] 7.3 ADR DUR033: why the condition is the primitive and the named wait its special case, and why evaluation is staged by journal position
