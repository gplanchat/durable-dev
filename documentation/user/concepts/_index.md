---
title: Concepts
weight: 20
---

# Concepts

This page introduces the vocabulary and mental model behind Durable. Read it before diving into the hands-on guides.

---

## Durable execution

**Durable execution** means that a long-running process — one that may span seconds, hours, or days — survives restarts, crashes, and deployments. The runtime records every decision (activity result, timer expiry, signal received) into a **history**, then **replays** that history to restore the exact state the program was in.

From the developer's perspective this feels like writing ordinary sequential PHP: `await` an activity, receive the result, continue. The runtime handles fault tolerance transparently.

---

## Workflow

A **workflow** is pure orchestration logic. It:

- Schedules **activities** (side-effect-bearing work) and awaits their results.
- Sets **timers** (wait for a duration or until a point in time).
- Reacts to **signals** (external one-way messages) and **updates** (messages with a response).
- Exposes read-only **queries** to inspect state without changing it.
- Spawns **child workflows** for sub-processes.

A workflow function must be **deterministic**: given the same history, re-executing it must produce the same sequence of commands. This is what makes replay possible.

**What does NOT belong in a workflow:**
- HTTP calls, database queries, random numbers, timestamps — all non-deterministic.
- Filesystem access, environment variable reads.
- Any I/O that would produce a different result on replay.

All of that belongs in **activities**.

---

## Activity

An **activity** is the unit of potentially-failing, non-deterministic work. Activities:

- Perform I/O: HTTP calls, database writes, emails, etc.
- Use dependency injection (repositories, HTTP clients, loggers).
- Are **retried** automatically on failure according to `ActivityOptions`.
- Have their result recorded once; on replay, the result is taken from history without re-executing the activity.

A workflow interacts with activities through an **`ActivityInvoker`** (typed stub obtained from `WorkflowEnvironment::activityStub()`). Calling a method on the stub returns an **`Awaitable`**; `await()` suspends the workflow until the activity completes.

---

## Event history and replay

The runtime stores every meaningful event in a **history** (also called a journal):

```
ExecutionStarted
  └─ ActivityScheduled(name: "charge-order", attempt: 1)
       └─ ActivityCompleted(name: "charge-order", result: "ok")
            └─ ExecutionCompleted(result: "done")
```

When the workflow process restarts — or when Temporal schedules a new workflow task — the runtime **replays** the workflow function against this history:

```
Replay step 1: await activity("charge-order")
  → history has ActivityCompleted for this step → return "ok" immediately (no real HTTP call)
Replay step 2: return "done"
  → history has ExecutionCompleted → workflow is finished
```

Because the result is in history, the activity handler is **not called again** during replay. The function simply continues from where it left off.

### Event types

| Event | When it is recorded |
|-------|---------------------|
| `ExecutionStarted` | Workflow accepted by the orchestrator |
| `ActivityScheduled` | Workflow scheduled an activity |
| `ActivityCompleted` | Activity returned a result |
| `ActivityFailed` | Activity threw an unhandled exception |
| `TimerStarted` | Workflow set a timer |
| `TimerFired` | Timer elapsed |
| `SignalReceived` | External signal delivered to the workflow |
| `UpdateAccepted` | Transactional update accepted |
| `ChildWorkflowStarted` | Child workflow dispatched |
| `ChildWorkflowCompleted` | Child workflow finished |
| `ExecutionCompleted` | Workflow returned a result |
| `WorkflowExecutionFailed` | Workflow threw an unhandled exception |

---

## Await and Awaitables

`WorkflowEnvironment::await()` is the single primitive for suspending the workflow. It accepts an **`Awaitable`** — a lightweight placeholder for a future result — and blocks (conceptually) until that result is available.

Under the hood, Durable uses **PHP Fibers** to suspend execution without blocking the OS thread. The fiber resumes when the orchestrator delivers the awaited result in a subsequent workflow task.

```
Fiber runs → hits await(activity) → no result in history → fiber suspends
                   ↓
         Temporal schedules new task with ActivityCompleted
                   ↓
         Fiber resumes from await → receives result → continues
```

You can compose awaitables:

```php
// Sequential
$a = $env->await($activities->stepA());
$b = $env->await($activities->stepB($a));

// Parallel (both start at once)
[$a, $b] = $env->await($env->all([
    $activities->stepA(),
    $activities->stepB(),
]));

// Race (first one wins)
$winner = $env->await($env->race([
    $activities->fastPath(),
    $activities->slowPath(),
]));
```

---

## Signals, queries, and updates

### Signal

A **signal** is a one-way external message delivered to a running workflow. The workflow can suspend with `waitSignal()` and resume when the signal arrives. Signals have no return value.

```
HTTP request → dispatchSignal("approve") → workflow resumes from waitSignal
```

Use signals for: approval flows, pause/resume, external triggers.

### Query

A **query** is a synchronous read of workflow state. The workflow exposes a `#[QueryMethod]` that reads an internal variable; the caller gets the current value without changing workflow state. Queries are **not** recorded in history.

Use queries for: checking progress, reading a counter, inspecting a list of pending items.

### Update

An **update** is a transactional message: the workflow validates and processes it, then returns a response. The interaction is recorded in history. Updates combine signal semantics (state change) with query semantics (return value).

Use updates for: incrementing a counter and returning the new value, conditional approvals that must return an acknowledgment.

---

## Timers

`WorkflowEnvironment::timer()` returns an `Awaitable` that resolves after a duration. Like activities, timers are durably recorded: after a restart the timer state is reconstructed from history and the workflow resumes at the right time without re-executing elapsed timers.

```php
// Wait 30 minutes before continuing
$env->await($env->timer(new \DateInterval('PT30M')));
```

---

## Child workflows

A workflow can **spawn child workflows** to decompose complex processes into independently-tracked sub-units. Each child has its own history and can be monitored separately in the Temporal UI.

Child workflows can run **asynchronously** (fire-and-forget) or be awaited by the parent.

---

## Backends

Durable runs on two backends that share the same workflow and activity code:

```
┌─────────────────────────────────────────────────────────────┐
│                  Application code                           │
│  (workflows, activities, WorkflowEnvironment)               │
└────────────────────┬────────────────────────────────────────┘
                     │ same API
          ┌──────────┴──────────┐
          ▼                     ▼
   ┌─────────────┐       ┌──────────────┐
   │  In-Memory  │       │   Temporal   │
   │  (tests,    │       │  (production,│
   │   local)    │       │  staging)    │
   └─────────────┘       └──────────────┘
```

### In-Memory

- Runs entirely in a single PHP process.
- No external server or infrastructure.
- Messenger in-memory transports simulate async activity dispatch.
- Ideal for all **automated tests** and quick local experiments.

### Temporal

- Production-grade orchestration with a real Temporal cluster.
- Full history persistence, durable retries, Temporal UI.
- Workers poll Temporal over gRPC via two Symfony Messenger consumers.
- Requires `ext-grpc` PHP extension.

For setup details, see [Backends](../backends/).

---

## Symfony Messenger integration

In Symfony applications, Durable uses **Messenger** to:

- Route **`ResumeWorkflowMessage`** → workflow task queue.
- Route **`ActivityMessage`** → activity task queue.
- Dispatch **signals**, **updates**, and **timer fire** messages on the synchronous bus.

This means workflows integrate naturally with Symfony's async infrastructure. With the Temporal backend, the Messenger transport is a **gRPC-backed polling transport** — the same consumer interface, different underlying protocol.

For configuration, see [Getting started](../getting-started/) and [Configuration reference](../configuration/).

---

## Determinism and the replay contract

The **replay contract** is the core constraint: any code inside `#[WorkflowMethod]` must produce the **same sequence of awaitable operations** given the same history.

**Allowed in a workflow:**
- Calling `activityStub()` methods → returns `Awaitable`
- Calling `await()`, `all()`, `race()`, `any()` → suspend/combine awaitables
- Calling `timer()` → set a timer
- Reading signal/update state set in `#[SignalMethod]` / `#[UpdateMethod]`
- Pure computation on workflow-local variables

**Not allowed in a workflow (do in activities instead):**
- `new \DateTime()` / `time()` / `random_int()` — non-deterministic
- `file_get_contents()`, `curl_exec()`, database queries
- `static` or global mutable state shared across workflow executions
- `sleep()` — use `timer()` instead

---

## Going deeper

- [Getting started](../getting-started/) — install, configure, write your first workflow end-to-end.
- [Backends](../backends/) — In-Memory vs Temporal, Docker setup, DSN reference.
- [Creating a workflow](../workflows/) — full workflow API with signals, queries, updates, child workflows.
- [Creating activities](../activities/) — `ActivityOptions`, retries, timeouts, dependency injection.
- [Testing workflows](../testing/) — `DurableTestCase`, `ActivitySpy`, `DurableBundleTestTrait`.
- [Configuration reference](../configuration/) — every `durable.yaml` key.
