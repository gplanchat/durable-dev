# DUR022 — Workflow class, interface, and WorkflowEnvironment

## Status

Accepted

## Context

Durable workflows must be **deterministic** and free of I/O (DUR003). Authors need a **typed contract** (interface) for registration and tests, and a **single runtime object** (`WorkflowEnvironment`) that exposes replay-safe async primitives and activity invokers.

This ADR is the normative definition of workflow **authoring**. DUR003 defines execution mechanics (fiber, replay). DUR013 defines Query / Signal / Update semantics aligned with Temporal. DUR027 defines the `WorkflowTaskRunner` algorithm.

## Decision

### Primary authoring rule — no I/O

> Workflow code must perform **no I/O** (network, database, filesystem) and no non-deterministic operations (raw system clock, unlogged randomness). All I/O belongs in activities.

This is the **primary rule**. All specific prohibitions are consequences:

- Calling `\Fiber::suspend()` directly, creating `\Fiber` instances, or calling `\Fiber::getCurrent()` is forbidden because it interferes with the Durable fiber scheduler.
- Using async components that internally use fibers (e.g. Symfony HTTP Client in async Revolt mode) is forbidden because they perform I/O or conflict with the scheduler.
- Reading from clocks, databases, HTTP endpoints, or the filesystem directly in workflow code is forbidden.

Documentation should name the **principle** (no I/O, determinism), not enumerate forbidden primitives.

### Interface and implementation class

- The library user provides:
  - A **workflow interface** annotated with `#[Workflow]` (attribute target: interface). The attribute carries the logical workflow type name.
  - A **concrete class** implementing that interface, registered as the workflow implementation.

### Constructor injection: only WorkflowEnvironment

- The workflow implementation class **must** declare **exactly one** constructor parameter: `WorkflowEnvironment $environment`.
- **No other** constructor parameters are allowed: no application services, repositories, or request-scoped objects. All side effects belong in activities (DUR023, DUR004).

### WorkflowMethod

- The interface must expose **at least one** method annotated with `#[WorkflowMethod]` (the primary entry point).
- If two or more `#[WorkflowMethod]` annotations exist, **exactly one** must set `default: true`.
- Parameters and return types must be serializable per DUR007.

### Signal, Query, Update

Optional methods on the same interface may be annotated with:

- `#[SignalMethod]` — external input; deterministic mutation of workflow state (see DUR013).
- `#[QueryMethod]` — read-only view of state; invoked post-replay without advancing the fiber (see DUR013, DUR027 §1).
- `#[UpdateMethod]` — validated update with response semantics; delivers an `UpdateAwaitable` into the fiber (see DUR013).

### WorkflowEnvironment

`WorkflowEnvironment` is injected into the workflow constructor. It is the **only** supported bridge between synchronous-looking workflow code and the replay-safe fiber scheduler.

Required API:

| Method | Description |
|---|---|
| `await(Awaitable $a): mixed` | Suspend the fiber until the awaitable completes (replay-safe) |
| `async(callable $fn): Awaitable` | Schedule async work compatible with the fiber model |
| `resolve(Awaitable $a, mixed $value): void` | Complete an internal async handle |
| `reject(Awaitable $a, \Throwable $e): void` | Fail an internal async handle |
| `all(Awaitable ...$awaitables): array` | Wait for all branches (used for parallel activities) |
| `race(Awaitable ...$awaitables): mixed` | First completion wins |
| `any(Awaitable ...$awaitables): mixed` | First useful result |
| `activity(string $interface): ActivityInvoker` | Obtain an activity invoker for the given interface (DUR023) |

### Activity invokers

Workflows do not construct activity implementations. They obtain `ActivityInvoker<ActivityInterface>` from `WorkflowEnvironment::activity(ActivityInterface::class)` and call activity methods as `Awaitable<T>`.

## Consequences

- DI containers and loaders must not inject non-runtime services into workflow classes
- Static analysis and documentation treat `WorkflowEnvironment` as the hub for the workflow-side API surface
- DUR003 defers the execution model to this ADR for the named type; DUR013 defers authoring attributes to this ADR

## Relationship to other ADRs

- **DUR003** — fiber and replay mechanics; `WorkflowEnvironment` is the workflow-side API
- **DUR013** — Query / Signal / Update behavior; DUR022 defines attributes and constructor rules
- **DUR023** — activity interfaces and invokers used from `WorkflowEnvironment`
- **DUR027** — `WorkflowTaskRunner` instantiates the workflow class and drives `WorkflowEnvironment`
