## 1. Establish the guard before moving anything

- [x] 1.1 Run the integration suite against a real server and record the result — it is the only check that catches a replay break
- [x] 1.2 Add a test that reads a journal written by the current code and replays it, so the wire format is pinned independently of the port signatures

## 2. Timers — the boundary that carries a real defect

- [x] 2.1 `WorkflowCommandBufferInterface::startTimer()` takes a `Duration` delay instead of a float deadline
- [x] 2.2 `EventStoreCommandBuffer` derives the deadline from the delay and its clock
- [x] 2.3 `TemporalWorkflowCommandBuffer` maps the delay straight to `start_to_fire_timeout`, dropping the `microtime()` subtraction and its clamp
- [x] 2.4 `WorkflowHistorySourceInterface::findTimerSlotResult()` returns a `Duration`
- [x] 2.5 Both history sources updated; `ExecutionContext::delay()` stops converting

## 3. Activities

- [x] 3.1 `scheduleActivity()` takes `ActivityOptions` instead of `array $metadata`
- [x] 3.2 `ExecutionContext::activity()` stops calling `toMetadata()`
- [x] 3.3 `EventStoreCommandBuffer` serialises for the journal; `TemporalWorkflowCommandBuffer` drops `ActivityOptions::fromMetadata()`
- [x] 3.4 `ActivityMessage` carries `ActivityOptions`; the transport owns its serialisation
- [x] 3.5 `ActivityMessageProcessor` and `ExecutionRuntime` stop rebuilding options from arrays

## 4. Child workflows

- [x] 4.1 `scheduleChildWorkflow()` takes `ChildWorkflowOptions`
- [x] 4.2 `TemporalWorkflowCommandBuffer` drops its three `fromMetadata()` calls
- [x] 4.3 `TemporalPolicyMapper` narrows `ParentClosePolicy|string|null` to the enum now that values arrive typed

## 5. Sweep

- [x] 5.1 No `toMetadata()` or `fromMetadata()` call remains in `src/Durable` outside the value objects themselves
- [x] 5.2 No port method takes `array $metadata` or a bare float timing
- [x] 5.3 phpstan clean, unit suite green

## 6. Prove the wire did not move

- [x] 6.1 Integration suite green against the same server, same namespace
- [ ] 6.2 An execution started before the change replays to completion after it
- [x] 6.3 Recorded journal entries compared byte-for-byte against the pinned fixture from task 1.2

## 7. Documentation

- [ ] 7.1 ADR recording where serialisation lives and why the wire format is frozen
- [ ] 7.2 Note the port break for third-party driver implementers
