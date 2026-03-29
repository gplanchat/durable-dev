# Ports and Adapters (hexagonal architecture)

ADR004-ports-and-adapters
===

Introduction
---

This **Architecture Decision Record** describes applying the Ports and Adapters pattern (hexagonal architecture) to the Durable project. The goal is to isolate workflow and activity logic from infrastructure details (persistence, transport, runtime).

Principle
---

Hexagonal architecture organizes code into three layers:

- **Domain (center)**: workflow business logic (orchestration, replay, slots)
- **Ports (interfaces)**: contracts defined by the domain to talk to the outside world
- **Adapters (infrastructure)**: concrete implementations of ports

Structure
---

```
┌─────────────────────────────────────────┐
│              Adapters                   │
│  ┌─────────────┐  ┌─────────────────┐   │
│  │ DbalEvent   │  │ Messenger       │   │
│  │ Store       │  │ transport       │   │
│  │             │  │ (bundle)        │   │
│  └─────────────┘  └─────────────────┘   │
│  ┌─────────────┐  ┌─────────────────┐   │
│  │ InMemory    │  │ DbalActivity    │   │
│  │ EventStore  │  │ Transport       │   │
│  └─────────────┘  └─────────────────┘   │
└─────────────────────────────────────────┘
                    │
            ┌───────▼───────┐
            │    Ports      │
            │ EventStore    │
            │ ActivityTrans-│
            │ port          │
            └───────┬───────┘
                    │
            ┌───────▼───────┐
            │   Domain      │
            │ ExecutionEng- │
            │ ine, Context  │
            └───────────────┘
```

### Workflow orchestration API (`WorkflowEnvironment`)

**Handlers** for workflows (classes annotated `#[Workflow]` or registered callables) receive a **`WorkflowEnvironment`** façade: timers, `await`, `activityStub()`, `childWorkflowStub()`, `sideEffect()`, distributed dispatch, etc. (see [ADR005](ADR005-messenger-integration.md), [ADR009](ADR009-distributed-workflow-dispatch.md)). Internally the engine relies on **`ExecutionEngine`** / **`ExecutionContext`**; `WorkflowEnvironment` is the **usage port** exposed to orchestration code, not an infrastructure adapter.

Durable project ports
---

### EventStoreInterface (outbound port)

```php
interface EventStoreInterface
{
    public function append(Event $event): void;
    public function readStream(string $executionId): iterable;
}
```

Port `EventStoreInterface`. Adapters: `DbalEventStore`, `InMemoryEventStore`

### ActivityTransportInterface (outbound port)

```php
interface ActivityTransportInterface
{
    public function enqueue(ActivityMessage $message): void;
    public function dequeue(): ?ActivityMessage;
    public function isEmpty(): bool;
}
```

Port `ActivityTransportInterface`. Adapters in the **library**: `DbalActivityTransport`, `InMemoryActivityTransport`. **Symfony Messenger** adapter: `Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport` (package **`gplanchat/durable-bundle`**, depends on `symfony/messenger`).

### ActivityExecutor (outbound port)

```php
interface ActivityExecutor
{
    public function execute(string $activityName, array $payload): mixed;
}
```

Adapter: `RegistryActivityExecutor` (Bundle)

### WorkflowBackendInterface (inbound port)

```php
interface WorkflowBackendInterface
{
    public function start(string $executionId, callable $handler): mixed;
}
```

Adapter: `LocalWorkflowBackend` (current implementation). A `TemporalWorkflowBackend` adapter may be added later (OST001).

References
---

- [Ports and Adapters (Alistair Cockburn)](https://alistair.cockburn.us/hexagonal-architecture/)
- [ADR005 - Messenger integration](ADR005-messenger-integration.md)
- [ADR006 - Activity patterns](ADR006-activity-patterns.md)
