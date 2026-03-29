# Symfony Messenger integration

ADR005-messenger-integration
===

Introduction
---

This **Architecture Decision Record** chooses **Symfony Messenger** as the transport for Durable project activities, and as the queue for **workflow resume** in distributed mode. This enables distributed execution without RoadRunner, using the existing Symfony ecosystem.

Context
---

The Durable project must run activities asynchronously and in a distributed way. Alternatives considered:

- **RoadRunner + Temporal**: requires RoadRunner, high complexity
- **Laravel Queues**: not applicable (Symfony project)
- **Symfony Messenger**: native Symfony, Redis, Dbal, SQS, etc.
- **Doctrine DBAL**: simple, no external dependency (Dbal transport)

Decision
---

Symfony Messenger is the **primary transport** for activity messages (`ActivityMessage`) and, when `durable.distributed` is enabled, for **`WorkflowRunMessage`** (start / resume run). The **library** `gplanchat/durable` exposes only the port `ActivityTransportInterface` and transport DTOs (`ActivityMessage`, etc.) **without** requiring `symfony/messenger`. The **bundle** `gplanchat/durable-bundle` provides the Messenger adapter, handlers, and DI wiring. A Dbal transport and an InMemory transport remain in the library for tests and lightweight deployments.

Implementation
---

### MessengerActivityTransport (bundle)

Class: `Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport`. Adapts `ActivityTransportInterface` to Messenger primitives:

- `enqueue()` → `SenderInterface::send()`
- `dequeue()` → `ReceiverInterface::get()` then `ack()`

### Configuration (bundle)

```yaml
# config/packages/durable.yaml
durable:
    distributed: true
    activity_transport:
        type: messenger
        transport_name: durable_activities
```

Routing (`WorkflowRunMessage`, `ActivityMessage`, etc.) to the correct transports is configured in **`config/packages/messenger.yaml`** (typical names: `durable_workflows`, `durable_activities`).

### Activity worker

The **`durable:activity:consume`** command consumes messages from the configured transport, runs activities via **`ActivityExecutor`**, and persists results to the **EventStore**.

### Workflow side (reminder)

Registered handlers are **`#[Workflow]`** classes whose **`#[WorkflowMethod]`** receives **`WorkflowEnvironment`**: activity calls go through **typed stubs** (`activityStub()`), which still enqueue via Messenger when the activity transport is Messenger ([ADR004](ADR004-ports-and-adapters.md), [OST003](../ost/OST003-activity-api-ergonomics.md)).

Distributed model
---

- **Inline mode**: workflow and activities in the same process (`InMemoryWorkflowRunner`, **`drainActivityQueueOnce`**)
- **Distributed mode**: activities consumed by workers; workflows consumed by **`WorkflowRunHandler`** on a dedicated transport; shared **EventStore** and optionally **resume metadata** (Dbal) — see [ADR007](ADR007-workflow-recovery.md) and [ADR009](ADR009-distributed-workflow-dispatch.md)

References
---

- [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
- [ADR004 - Ports and Adapters](ADR004-ports-and-adapters.md)
- [ADR007 - Recovery and replay](ADR007-workflow-recovery.md)
- [ADR009 - Workflow re-dispatch](ADR009-distributed-workflow-dispatch.md)
