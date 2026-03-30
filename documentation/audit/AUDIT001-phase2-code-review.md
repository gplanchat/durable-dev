# Code audit — Phase 2

AUDIT001-phase2-code-review
===

Introduction
---

This report documents the `src/` code audit performed as part of Phase 2 of the Durable refactor plan. It checks consistency, separation of concerns, and alignment with ADRs.

Scope
---

- **Directory**: `src/` (monorepo layout: use `src/Durable/` and `src/DurableBundle/` as applicable)
- **Date**: Phase 2
- **References**: ADR001–ADR009, hive/runtime/compiler architecture

Consistency and separation of concerns
---

### Component (pure logic)

| File / folder | Responsibility | Dependencies | Compliance |
|---------------|----------------|--------------|------------|
| `ExecutionEngine` | Workflow startup | EventStoreInterface, ExecutionRuntime | OK — no HttpKernel |
| `ExecutionContext` | Execution context, replay, slots | EventStoreInterface, ActivityTransportInterface | OK |
| `ExecutionRuntime` | Await loop, drain, timers | EventStoreInterface, ActivityTransportInterface, ActivityExecutor | OK |
| `Store/` | Event / metadata persistence | Doctrine (optional) | OK — ports defined |
| `Transport/` | Activity transport, workflow messages | Messenger (optional) | OK — ports defined |
| `Event/` | Domain events | None | OK |
| `Awaitable/` | Promises / Deferred | None | OK |
| `Port/` | Interfaces (WorkflowBackend, WorkflowResumeDispatcher) | None | OK |
| `WorkflowRegistry` | Workflow registration by type | None | OK |

### Bundle (Symfony integration)

| File / folder | Responsibility | Compliance |
|---------------|----------------|------------|
| `DurableBundle` | Bundle registration | OK |
| `DependencyInjection/` | DI configuration, parameters | OK |
| `Handler/ActivityRunHandler` | Activity consumption (Messenger `from_transport`) | OK |
| `Handler/WorkflowRunHandler` | Workflow execution (Messenger) | OK |
| `Messenger/MessengerWorkflowResumeDispatcher` | Workflow re-dispatch | OK |

### Ports and adapters (ADR004)

- **EventStoreInterface** / **ActivityTransportInterface**: canonical ports used throughout
- **WorkflowBackendInterface**: port for backends (LocalWorkflowBackend implemented)
- **WorkflowResumeDispatcher**: port for re-dispatch (Null + Messenger)

Tests
---

- 19 PHPUnit tests, 57 assertions — all green (figures at audit time)
- Coverage: unit (FunctionsTest, Awaitable), integration (Bundle, Messenger, Dbal, Maquette)
- ADR003 compliance: InMemoryEventStore, InMemoryActivityTransport, no excessive mocks

Points of attention
---

1. **Timers in distributed mode**: `delay()` with `distributed=true` raises `WorkflowSuspendedException` — wake mechanism (timer table, cron) was not yet implemented (OST001).
2. **WorkflowRunHandler**: requires `messenger.default_bus` when `distributed=true` — the app must configure Messenger.
3. **Interfaces**: `EventStoreInterface` and `ActivityTransportInterface` are the main service identifiers.

Conclusion
---

The code is **consistent** with ADRs and component/bundle separation is respected. Ports are clearly identified. Phase 2 considered complete.

References
---

- [ADR004 - Ports and Adapters](../adr/ADR004-ports-and-adapters.md)
- [ADR009 - Distributed model](../adr/ADR009-distributed-workflow-dispatch.md)
- [PRD001 - Current state](../prd/PRD001-current-component-state.md)
