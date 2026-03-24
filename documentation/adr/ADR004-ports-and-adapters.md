# Architecture Ports et Adapters (Hexagonale)

ADR004-ports-and-adapters
===

Introduction
---

Ce **Architecture Decision Record** décrit l'application du pattern Ports and Adapters (architecture hexagonale) au projet Durable. L'objectif est d'isoler la logique de workflows et d'activités des détails d'infrastructure (persistence, transport, runtime).

Principe
---

L'architecture hexagonale organise le code en trois couches :

- **Domaine (centre)** : logique métier des workflows (orchestration, replay, slots)
- **Ports (interfaces)** : contrats définis par le domaine pour communiquer avec l'extérieur
- **Adapters (infrastructure)** : implémentations concrètes des ports

Structure
---

```
┌─────────────────────────────────────────┐
│              Adapters                   │
│  ┌─────────────┐  ┌─────────────────┐   │
│  │ DbalEvent   │  │ Messenger       │   │
│  │ Store       │  │ ActivityTransport│   │
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

### API d’orchestration côté workflow (`WorkflowEnvironment`)

Les **handlers** de workflow (classes annotées `#[Workflow]` ou callables enregistrés) reçoivent une façade **`WorkflowEnvironment`** : timers, `await`, `activityStub()`, `childWorkflowStub()`, `sideEffect()`, dispatch distribué, etc. (voir [ADR005](ADR005-messenger-integration.md), [ADR009](ADR009-distributed-workflow-dispatch.md)). En interne, le moteur s’appuie sur **`ExecutionEngine`** / **`ExecutionContext`** ; `WorkflowEnvironment` est le **port d’usage** exposé au code métier d’orchestration, pas un adapter d’infrastructure.

Ports du projet Durable
---

### EventStoreInterface (port sortant)

```php
interface EventStoreInterface
{
    public function append(Event $event): void;
    public function readStream(string $executionId): iterable;
}
```

Port `EventStoreInterface`. Adapters : `DbalEventStore`, `InMemoryEventStore`

### ActivityTransportInterface (port sortant)

```php
interface ActivityTransportInterface
{
    public function enqueue(ActivityMessage $message): void;
    public function dequeue(): ?ActivityMessage;
    public function isEmpty(): bool;
}
```

Port `ActivityTransportInterface`. Adapters : `MessengerActivityTransport`, `DbalActivityTransport`, `InMemoryActivityTransport`

### ActivityExecutor (port sortant)

```php
interface ActivityExecutor
{
    public function execute(string $activityName, array $payload): mixed;
}
```

Adapter : `RegistryActivityExecutor` (Bundle)

### WorkflowBackendInterface (port entrant)

```php
interface WorkflowBackendInterface
{
    public function start(string $executionId, callable $handler): mixed;
}
```

Adapter : `LocalWorkflowBackend` (implémentation actuelle). Un adapter `TemporalWorkflowBackend` pourra être ajouté à l'avenir (OST001).

Références
---

- [Ports and Adapters (Alistair Cockburn)](https://alistair.cockburn.us/hexagonal-architecture/)
- [ADR005 - Intégration Messenger](ADR005-messenger-integration.md)
- [ADR006 - Patterns activité](ADR006-activity-patterns.md)
