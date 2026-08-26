## 0. Unblock

- [ ] 0.1 `workflow-side-deadlines` landed and archived, so `openspec/specs/workflow-deadlines/` exists and this change's removal delta has a requirement to point at
- [ ] 0.2 The in-flight awaitable refactor (composite / quorum) committed — `WorkflowEnvironment` is the single entry point both changes touch

## 1. Establish the evaluation loop before writing the primitive

- [x] 1.1 Write down, in journal-position terms, the interleaving of message application, handler dispatch and condition re-evaluation, and record it in `design.md` — a position is a stream rank, the wait drives the cursor, and both P and Q are stream positions or the comparison is meaningless
- [ ] 1.2 Check whether a Temporal workflow task can carry several journaled messages at once, so interleaving is an ordering question inside one task and not only across tasks
- [ ] 1.3 Probe, against the running server, how a worker accepts and completes an update — which messages carry the acceptance and the response, and on which task they are returned. Nothing about update responses reaches the domain before this is seen
- [ ] 1.4 Record what the probes changed, if anything, in `design.md`

## 2. Failing tests first — conditions

- [ ] 2.1 A condition that already holds does not suspend, and records nothing that would wake the execution
- [ ] 2.2 A condition becomes true on a delivered message and the workflow resumes there
- [ ] 2.3 Replay resumes at the same journal position and takes the same path
- [ ] 2.4 A condition under a deadline that does not hold in time raises the timeout failure, and a message recorded afterwards does not undo it — the DUR032 guarantee, restated on conditions
- [ ] 2.5 Two messages that each satisfy a pending condition are applied one at a time, and the workflow resumes on the first
- [ ] 2.6 A condition that can never hold is reported as unable to advance, naming the condition, instead of spinning
- [ ] 2.7 A condition whose outcome depends on something the journal does not record is reported as a divergence, not silently resolved

## 3. Failing tests first — dispatch

- [ ] 3.1 An annotated method handles the signal it names, and the state it mutates is visible to the body
- [ ] 3.2 A workflow **class** declares its handler by attribute and behaves identically to the annotated case above, run through the harness's class-based entry point (`workflow-authoring-surface`)
- [ ] 3.3 A message with no declared handler is recorded and does not fail the execution
- [ ] 3.4 Two signals are handled in recorded order, identically on every replay
- [ ] 3.5 Three deliveries of one name reach the handler three times, on a first run and on replay
- [ ] 3.6 A delivery recorded while no await was pending is still observed by the next one
- [ ] 3.7 An update handler's return value reaches the caller, and survives replay
- [ ] 3.8 A raising update handler fails the update, not the workflow

## 4. Domain — conditions

- [ ] 4.1 `await()` accepts a condition, wrapped into the existing awaitable contract, composing with the deadline path unchanged
- [ ] 4.2 The evaluation loop from 1.1: messages applied one at a time, pending conditions re-tested after each
- [ ] 4.3 Divergence and never-true conditions reported through the existing "cannot advance" path rather than a new failure vocabulary

## 5. Domain — dispatch

- [ ] 5.1 `#[SignalMethod]` and `#[UpdateMethod]` read at load time, alongside the existing `#[QueryMethod]` scan
- [ ] 5.2 ~~Imperative registration on `WorkflowEnvironment`~~ — dropped: it existed only so a closure could declare a handler, and the harness now runs classes
- [ ] 5.3 Engine-side dispatch, interleaved with 4.2
- [ ] 5.4 Update responses recorded and reproduced on replay
- [ ] 5.5 Worker-side update acceptance and completion on the Temporal bridge, from the probe in 1.3

## 6. Deletions

- [ ] 6.1 `waitSignal()` and `waitUpdate()` removed from `WorkflowEnvironment`
- [ ] 6.2 The signal wait slot index, the per-name counter and `releaseSignalWaitSlot()` removed from `ExecutionContext`
- [ ] 6.3 The deadline-aware argument on `findSignalForSlot()` removed from the port and both backends — and the method itself if nothing else reads it
- [ ] 6.4 Symfony samples and integration fixtures migrated to handler + condition
- [ ] 6.5 The deadline tests rewritten onto conditions, asserting the same outcomes — a test that loses an assertion instead of changing shape means a guarantee was lost with the method

## 7. Backend parity

- [ ] 7.1 Same workflow, same handlers, same order, in-memory and Temporal, including the replay path
- [ ] 7.2 Integration test against a real server for a condition satisfied by a signal delivered after a deadline fired — the DUR032 case, on its new foundation
- [ ] 7.3 Integration test against a real server for an update that answers

## 8. Documentation

- [ ] 8.1 Signals documented as handler + condition, with the `waitSignal()` migration written out, because it is the rewrite every existing workflow has to make
- [ ] 8.2 Conditions documented with their determinism rule: a predicate reads workflow state and nothing else
- [ ] 8.3 ADR DUR033: why the condition is the primitive rather than a second wait method, why evaluation is interleaved with message application, and what that let us delete
