# OST004 — Workflow / Temporal feature parity (PHP)

## Context

The **Durable** component follows the **Temporal** spirit (see [OST003](OST003-activity-api-ergonomics.md) for activity ergonomics). To guide the **engine**, **event log**, and **public APIs**, the capabilities below — as documented for the **official Temporal PHP SDK** — must be **supported** or **mapped** explicitly (full support, subset, or documented out-of-scope).

Links point to Temporal Platform documentation.

---

## 1. Side effects

**Reference**: [Side Effects — PHP SDK | Temporal](https://docs.temporal.io/develop/php/side-effects)

**Temporal behavior**: run **non-deterministic** code (UUID, `random_int`, real “now” timestamps, etc.) **without** breaking workflow determinism: the result is **persisted in execution history**; on **replay**, the closure is **not re-run** — the stored value is reused. An exception in the side effect fails the workflow task. Do not mutate workflow state **inside** the side effect (only return a value).

**Durable implications**:

- Dedicated event(s) in the log (equivalent “side effect result”) + read-back on replay.
- Target API such as **`sideEffect(Closure): Awaitable<T>`** (or method on `ExecutionContext` / workflow façade), aligned with **`Workflow::sideEffect()`** on the Temporal side.
- Consistency with **determinism** and **replay** rules ([ADR007](../adr/ADR007-workflow-recovery.md)).

---

## 2. Durable timers

**Reference**: [Durable Timers — PHP SDK | Temporal](https://docs.temporal.io/develop/php/timers)

**Temporal behavior**: **`Workflow::timer($seconds)`** — durable sleep; timers are **persisted**; after worker/service downtime, resume continues at the right time. Temporal docs: do not nest a timer in certain `await` / `awaitWithTimeout` paths (SDK version dependent).

**Durable implications**:

- **Done**: `TimerScheduled` / `TimerCompleted`, **`WorkflowEnvironment::timer` / `delay`** API; in distributed mode, **`FireWorkflowTimersMessage`** + handler to complete timers and re-dispatch resume (injectable clock on **`ExecutionRuntime`** for tests).
- Document **nesting** limitations with internal `await` if they still differ from the Temporal SDK.

---

## 3. Child workflows

**Reference**: [Child Workflows — PHP SDK | Temporal](https://docs.temporal.io/develop/php/child-workflows)

**Temporal behavior**: schedule a workflow execution **from** a parent workflow; dedicated history events (`StartChildWorkflowExecution*`, etc.); **stub** per child (`newChildWorkflowStub`, options, `yield` on promise); **Parent Close Policy** (terminate / abandon / request cancel). **Untyped** stub with workflow name string possible.

**Durable implications**:

- **Done (core)**: parent events `ChildWorkflowScheduled` / `Completed` / `Failed`; result via **`WorkflowEnvironment`** (`executeChildWorkflow`, **`childWorkflowStub`**); **`ChildWorkflowOptions`** (`workflowId`, **parent close policy**); **inline** or **async Messenger** child; parent↔child link persistence (**DBAL**); child failure projected on parent log (**kind / class / context**) and read on replay via **`DurableChildWorkflowFailedException`**.
- **Remaining / partial**: Temporal-fine timeouts, all policy variants, 100% SDK “stub” client ergonomics, run id correlation / same logical id as Temporal.
- The **`#[Workflow]`** attribute on child types (see [OST003](OST003-activity-api-ergonomics.md)) provides a stable **logical name**; see [ADR011](../adr/ADR011-child-workflow-continue-as-new.md).

---

## 4. Continue-as-new

**Reference**: [Continue-As-New — PHP SDK | Temporal](https://docs.temporal.io/develop/php/continue-as-new)

**Temporal behavior**: close the current execution **successfully** and start a **new** one (same **Workflow Id**, new **Run Id**, **fresh** history), typically passing **state** as parameters. **`Workflow::getInfo()->shouldContinueAsNew`** for history limits. **Note**: with **Updates** / **Signals**, do not call continue-as-new **from** handlers — wait for handlers to finish on the main path (Temporal documentation).

**Durable implications**:

- Explicit engine operation (**`continueAsNew(...)`**) + log cut / run chaining.
- **Safety** rules with async handlers (signals / updates / queries): same principle as Temporal.
- Dedicated tests (optional “test hook” to force history threshold in CI, as in Temporal samples).

---

## 5. Signals, queries, updates

**Reference**: [Workflow message passing — PHP SDK | Temporal](https://docs.temporal.io/develop/php/message-passing)

**Temporal behavior (summary)**:

| Mechanism | Role | Notable constraints |
|-----------|------|----------------------|
| **Signal** | Async message to a running execution | `#[SignalMethod]`, **`void`** return; may update state; patterns with **`Workflow::await()`**. |
| **Query** | **Synchronous** state read | `#[QueryMethod]`, **non-void** return; **no** logic that schedules commands (no activity / timer in handler). |
| **Update** | **Durable** mutation with response | `#[UpdateMethod]`; optional **`#[UpdateValidatorMethod]`** validator; may use activities, timers, children; **`startUpdate`** / **Update-with-Start** on client; **unfinished handler** policies; **`Workflow::allHandlersFinished()`** before workflow end; **`Mutex`** / **`Workflow::runLocked`** for concurrency; **`#[WorkflowInit]`** to initialize before handlers. |

**Durable implications**:

- **PHP attributes** mirroring the Temporal model: at minimum **`WorkflowMethod`** (entry), **`SignalMethod`**, **`QueryMethod`**, **`UpdateMethod`** (+ validator if needed) — consistent with **`#[Workflow]`** at class/interface level ([OST003](OST003-activity-api-ergonomics.md)).
- Log events: signal received, query served, update accepted / rejected / completed.
- **PHPStan / Psalm plugins** (planned in OST003): extend to message methods on the workflow interface.
- **Dynamic** components (dynamic handlers): advanced option, after the static path.

---

## Summary — prioritization hints

| Feature | Depends on log / engine | OST003 / ADR link |
|---------|-------------------------|-------------------|
| Side effects | Yes (new event type + replay) | ADR007, `ExecutionContext` API |
| Durable timers | Yes (refine existing `delay` / timer) | PRD001 current state |
| Child workflows | Yes (execution graph + events) | `#[Workflow]`, ADR009 |
| Continue-as-new | Yes (run chaining) | ADR007 |
| Signals / Queries / Updates | Yes (handlers + client) | OST003 `#[Workflow]`, future method attributes |

## Suggested next steps

1. ~~**ADR**~~: **[ADR010](../adr/ADR010-temporal-parity-events-and-replay.md)** — inventory of **event types** and replay per capability.
2. ~~**PRD**~~: **[PRD001](../prd/PRD001-current-component-state.md)** — **Temporal ↔ Durable** matrix and up-to-date event list.
3. **Roadmap**: child workflows → continue-as-new → messages (signals / queries / updates); side effects and timers covered by ADR010 and current implementation.

## Durable status matrix (summary)

Detailed reference: **[PRD001](../prd/PRD001-current-component-state.md)**. Short table for the roadmap:

| Temporal area (PHP SDK) | Durable support | Comment |
|-------------------------|-----------------|---------|
| Side effects | **Yes** | `SideEffectRecorded`, `WorkflowEnvironment::sideEffect` |
| Durable timers | **Yes** | `FireWorkflowTimersMessage` in distributed |
| Child workflows | **Partial** | Log + inline / async + DBAL link + enriched parent failure |
| Continue-as-new | **Partial** | New `executionId` |
| Signals / Queries / Updates | **Partial** | Log + Messenger; queries = log read |

---

## External references

- [Side effects (PHP)](https://docs.temporal.io/develop/php/side-effects)
- [Durable timers (PHP)](https://docs.temporal.io/develop/php/timers)
- [Child workflows (PHP)](https://docs.temporal.io/develop/php/child-workflows)
- [Continue-as-new (PHP)](https://docs.temporal.io/develop/php/continue-as-new)
- [Message passing — Signals, Queries, Updates (PHP)](https://docs.temporal.io/develop/php/message-passing)

## Internal references

- [OST003 — Activity ergonomics / `#[Workflow]` / `#[Activity]`](OST003-activity-api-ergonomics.md)
- [PRD001 — Current state](../prd/PRD001-current-component-state.md)
- [ADR007 — Workflow recovery](../adr/ADR007-workflow-recovery.md)
- [ADR009 — Distributed workflow dispatch](../adr/ADR009-distributed-workflow-dispatch.md)
- [ADR010 — Events and replay (Temporal parity)](../adr/ADR010-temporal-parity-events-and-replay.md)
