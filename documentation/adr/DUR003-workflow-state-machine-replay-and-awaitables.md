# DUR003 — Fiber-based replay, ExecutionEngine, and awaitables

## Status

Accepted

## Context

A Durable workflow must **suspend** at wait points (activities, timers, signals), **persist** progress in Temporal history (DUR001), and **replay** the same code deterministically from scratch on each `PollWorkflowTaskQueue` response (DUR027). PHP `\Fiber` is the interruptible execution primitive used by the component.

Workflow authoring rules, `WorkflowEnvironment`, and activity invokers are defined in **DUR022** and **DUR023**. The concrete `WorkflowTaskRunner` algorithm is defined in **DUR027**. This ADR defines the fiber-based execution model, the replay invariants, and the awaitable primitives.

## Decision

### Primary authoring rule — no I/O

> Workflow code must perform **no I/O** (network, database, filesystem) and no non-deterministic operations (raw system clock, unlogged randomness). All I/O belongs in activities.

This is the **primary rule**. All other restrictions are technical consequences:

- Calling `\Fiber::suspend()` directly, creating `\Fiber` instances, or calling `\Fiber::getCurrent()` in workflow code is **forbidden** — not as a standalone rule, but because any code doing so would interfere with the Durable fiber scheduler or perform I/O.
- Using async components that internally use fibers or coroutines (e.g. Symfony HTTP Client in async Revolt mode) is **forbidden** for the same reason: those components perform I/O.

### ExecutionEngine — the fiber manager

`ExecutionEngine` manages the `\Fiber` lifecycle for a single workflow execution cycle:

- Creates a `\Fiber` wrapping the `#[WorkflowMethod]` call
- Starts the fiber; on each suspension receives the `Awaitable` that caused it
- Passes each awaitable back to `WorkflowTaskRunner`, which decides whether to resolve it (replay) or record a new command (new slot)
- On fiber completion: emits a `CompleteWorkflow` event on `WorkflowCommandBufferInterface`
- On unhandled `\Throwable`: emits a `FailWorkflow` event on `WorkflowCommandBufferInterface`
- Is **shared** between Temporal and in-memory backends; only the injected ports differ

### Fiber lifecycle invariants

- **Fibers are non-persistent**: a `\Fiber` is created and destroyed within a single `PollWorkflowTaskQueue → RespondWorkflowTaskCompleted` cycle. The workflow code is replayed from scratch on every poll.
- **PHP-CLI standard runtime only**: Swoole, OpenSwoole, and FrankenPHP coroutine mode are not supported as workflow worker runtimes. These runtimes implement their own coroutine/fiber systems that conflict with the Durable fiber scheduler.
- **`pcntl_fork()` is forbidden** in the component and bundle (see DUR027 §5).

### Awaitables

Awaitables are the synchronization primitives between workflow code and the fiber scheduler:

- `ActivityAwaitable` — represents a pending activity; resolved when `ACTIVITY_TASK_COMPLETED` is found in history for the corresponding slot, or a new `ScheduleActivityTask` command is emitted for a new slot
- `TimerAwaitable` — represents a timer; resolved when `TIMER_FIRED` is found in history, or a new `StartTimer` command is emitted
- `SignalAwaitable` — resolved when `WORKFLOW_EXECUTION_SIGNALED` with the matching name is found in history
- `UpdateAwaitable` — resolved when `WORKFLOW_EXECUTION_UPDATE_ACCEPTED` / `WORKFLOW_EXECUTION_UPDATE_COMPLETED` events are found

### Determinism

A workflow must be **deterministic**: for the same event stream (history), the workflow code must produce the same sequence of awaitables and the same commands. No dependence on wall-clock time, unlogged randomness, or I/O.

`WorkflowTaskRunner` enforces determinism by:
1. Consuming history events strictly in chronological order (slot N must be resolved before slot N+1 is encountered)
2. Comparing the awaitable type and identity at each suspension point against the history record for that slot
3. Raising a non-determinism error if a mismatch is detected

### Replay loop (summary)

The full algorithm is specified in **DUR027**. In brief:

```
for each fiber suspension:
    slot = next slot in WorkflowHistorySource
    if slot has a recorded result:
        resolve the awaitable → resume fiber
    else:
        record a new command in WorkflowCommandBuffer → stop
```

### WorkflowEnvironment (named workflow-side API)

`WorkflowEnvironment` (DUR022) is the only API surface exposed to workflow code. It provides:

- `await(Awaitable $a): mixed` — suspend until the awaitable completes (replay-safe)
- `async(callable $fn): Awaitable` — schedule async work compatible with the fiber model
- `all(Awaitable ...$awaitables): array` — wait for all branches; parallel activities use this
- `race(Awaitable ...$awaitables): mixed` — first completion wins
- `any(Awaitable ...$awaitables): mixed` — first useful result
- Activity invokers from `WorkflowEnvironment::activity(ActivityInterface::class): ActivityInvoker` (DUR023)

## Consequences

- Any workflow pattern expressible in PHP — conditionals, loops, parallel activities, timers, signals, child workflows — is correctly supported because the actual user code runs inside the fiber
- The former exception-based suspension model (`WorkflowSuspendedException`) is replaced; suspension is `\Fiber::suspend()`
- Integration tests assert Temporal history events produced by real workflow code execution (DUR010)

## Relationship to other ADRs

- **DUR001** — event store; `WorkflowHistorySourceInterface` wraps event data for fiber replay
- **DUR002** — `WorkflowHistorySourceInterface` (read) and `WorkflowCommandBufferInterface` (write) are the ports injected into `ExecutionContext`
- **DUR022** — workflow authoring; `WorkflowEnvironment` is the sole API surface for workflow code
- **DUR023** — activity authoring; workflow code reaches activities only through `WorkflowEnvironment`
- **DUR027** — `WorkflowTaskRunner` is the concrete implementation of the replay loop described here
