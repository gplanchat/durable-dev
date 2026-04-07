# DUR023 — Activity authoring and asynchronous activity invoker

## Status

Accepted

## Context

**Activities** carry **I/O** and non-deterministic work (DUR004). **Workflows** are deterministic and receive only **`WorkflowEnvironment`** (DUR022). The workflow therefore needs a **typed proxy** from the activity **contract** (interface) to **asynchronous** calls that record history and replay, without the workflow ever instantiating the real activity class (blue/red separation).

This ADR defines **activity authoring** and names the **canonical** workflow-side proxy type.

## Decision

### Activity implementation class

- The user provides a **concrete activity class** that may use **dependency injection** in its constructor (services, repositories, loggers, etc.) as allowed by the **activity worker** host (e.g. Symfony container for the worker process).
- Constructor parameters are **not** limited to a single type: any dependency the user needs for I/O is permitted, subject to deployment and security constraints.

### Activity contract interface

- The user provides an **interface** (or equivalent contract) that describes the **methods callable from the workflow** for this activity type.
- Each such method is marked with **`#[ActivityMethod]`** (and may be complemented by **`#[Activity]`** on the implementation class for naming — see **DUR004**).
- **Argument and return types** must be **serializable** through the component pipeline (**DUR007**).

### Asynchronous invoker (workflow side)

- From **`WorkflowEnvironment`** (DUR022), the workflow obtains a **generic invoker** bound to the **activity interface** type.
- For every **`#[ActivityMethod]`** on that interface, the invoker exposes a method with the **same name and parameter list**, but the **return type** is **`Awaitable<T>`** (or the component’s equivalent primitive) where **`T`** is the **synchronous** return type declared on the activity interface.
- This object **does not** perform I/O in the workflow process: it **schedules** a durable step and returns an awaitable tied to history (see **DUR004** single scheduling primitive).

### Canonical name: ActivityInvoker

- The **canonical** PHP-level name for this generic proxy is **`ActivityInvoker`** (optionally parameterized by the activity interface in docblocks or generics when available).
- **Rationale**: avoids confusion with Remote Procedure Call “stubs” in other ecosystems; emphasizes **invoking** a durable activity from workflow code.

#### Naming alternatives (non-canonical)

| Candidate | Pros | Cons |
|-----------|------|------|
| **ActivityProxy** | Familiar pattern name | May suggest generic network proxies |
| **ActivityInvoker** | Clear call-from-workflow semantics | Less explicit about “async” |
| **ActivitySchedule** / **ScheduledActivity** | Evokes history scheduling | Overloads “schedule” with timers/cron |
| **DurableActivityHandle** | “Handle” suggests durable reference | “Handle” overloaded in PHP/OS |
| **AsyncActivityClient** | Explicit async + client role | Verbose |
| **ActivityCalls** | Neutral | Non-standard |

Documentation and new code **should** use **`ActivityInvoker`**. The term **ActivityStub** may appear in migration notes only as a **deprecated alias** for the same concept (see **DUR004**).

### Relation to DUR004

- **DUR004** keeps the **single** low-level scheduling primitive and contract resolution; **DUR023** defines **user-facing** shape of the **ActivityInvoker** and activity/contract pairing.

## Consequences

- Workers register **implementations**; workflows hold **invokers** created via **`WorkflowEnvironment`**.
- PHPStan / static analysis extensions should infer **`Awaitable<T>`** return types from **`ActivityMethod`**-annotated interface methods.
- **DUR004** is amended to use **ActivityInvoker** as the primary term.

## Relationship to other ADRs

- **DUR004** — Scheduling primitive, serialization, retries at orchestration level.
- **DUR007** — Payload serialization for activity methods.
- **DUR022** — Workflows obtain invokers only through **`WorkflowEnvironment`**.
