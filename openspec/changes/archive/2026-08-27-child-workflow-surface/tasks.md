# Tasks

## 1. Failing tests first

- [x] 1.1 A stub call starts the child and returns an awaitable, not the child's result
- [x] 1.2 Two children started through stubs can be raced, and the loser is cancelled
- [x] 1.3 Calling something other than the child's entry method fails, naming the expected one
- [x] 1.4 The environment exposes neither `scheduleChildWorkflow()` nor `executeChildWorkflow()`

## 2. Domain

- [x] 2.1 A narrow child-scheduling port, built by `childWorkflowStub()` and never returned
- [x] 2.2 `ChildWorkflowStub::__call()` returns an `Awaitable`
- [x] 2.3 Remove both verbs from `WorkflowEnvironment`

## 3. Call sites

- [x] 3.1 `ParallelChildEchoWorkflow` uses the stub — the case that motivated the change
- [x] 3.2 The four `SyncChildWorkflowTest` call sites, and `HarnessParityTest`
- [x] 3.3 `IntegrationWorkflows`
- [x] 3.4 Check that nothing on `ExecutionContext` or the command buffer was touched: those are
      engine-side and a workflow never receives them

## 4. Documentation and decision record

- [x] 4.1 The `WorkflowEnvironment` table and the child-workflow section of the workflow guide
- [x] 4.2 The testing guide's child-workflow example
- [x] 4.3 DUR038 — a stub assembles, it does not wait, and why DUR033 did not catch it

## 5. Verification

- [x] 5.1 Unit suite green, PHPStan clean — **PHPStan matters more than usual here**: the return
      type changed silently, so compilation alone proves nothing
- [x] 5.2 Integration suite green against a real server, to back the claim that no command moved
