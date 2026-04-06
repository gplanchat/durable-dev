# Symfony Messenger integration

ADR005-messenger-integration
===

Introduction
---

This **Architecture Decision Record** chooses **Symfony Messenger** as the transport for Durable project activities, and as the queue for **workflow resume** when the Symfony bundle is used. This enables distributed execution without RoadRunner, using the existing Symfony ecosystem.

Context
---

The Durable project must run activities asynchronously and support workflows that suspend across processes. Alternatives considered:

- **RoadRunner + Temporal**: requires RoadRunner, high complexity
- **Laravel Queues**: not applicable (Symfony project)
- **Symfony Messenger**: native Symfony, Redis, Dbal, SQS, etc.
- **Doctrine DBAL**: simple, no external dependency (Dbal transport)

Decision
---

Symfony Messenger is the **primary transport** for activity messages (`ActivityMessage`) and for **`WorkflowRunMessage`** (start / resume run) **in the bundle**. The **library** `gplanchat/durable` exposes only the port `ActivityTransportInterface` and transport DTOs (`ActivityMessage`, etc.) **without** requiring `symfony/messenger`. The **bundle** `gplanchat/durable-bundle` provides the Messenger adapter, handlers, and DI wiring. A Dbal transport and an InMemory transport remain in the library for tests and lightweight deployments.

There is **no** `durable.distributed` (or equivalent) flag: the bundle **always** wires **`WorkflowRunHandler`**, **`WorkflowResumeDispatcher`**, and related Messenger services. The **`ExecutionRuntime`** constructor still accepts a boolean **`$distributed`** for **library** use (e.g. **`InMemoryWorkflowRunner`**, tests with synchronous activity drain when `false`).

Implementation
---

### MessengerActivityTransport (bundle)

Class: `Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport`. Adapts `ActivityTransportInterface` to Messenger primitives:

- `enqueue()` → `SenderInterface::send()`
- `dequeue()` → `ReceiverInterface::get()` then `ack()` (used for inline / non-handler consumption paths in tests; production workers use `messenger:consume` instead)

### Configuration (bundle)

```yaml
# config/packages/durable.yaml
durable:
    activity_transport:
        type: messenger
        transport_name: durable_activities
```

Routing (`WorkflowRunMessage`, `ActivityMessage`, etc.) to the correct transports is configured in **`config/packages/messenger.yaml`** (typical names: `durable_workflows`, `durable_activities`).

### Activity worker

When **`activity_transport.type`** is **`messenger`**, **`ActivityRunHandler`** is registered as a **`messenger.message_handler`** restricted to the configured transport (`from_transport`). Activities are executed via **`ActivityMessageProcessor`** (same logic as before) and **`messenger:consume`** on the activity transport (e.g. `durable_activities`). There is **no** separate console command for activity consumption.

### Workflow side (reminder)

Registered handlers are **`#[Workflow]`** classes whose **`#[WorkflowMethod]`** receives **`WorkflowEnvironment`**: activity calls go through **typed stubs** (`activityStub()`), which still enqueue via Messenger when the activity transport is Messenger ([ADR004](ADR004-ports-and-adapters.md), [OST003](../ost/OST003-activity-api-ergonomics.md)).

Bundle vs library execution model
---

- **Library / tests (synchronous drain)**: workflow and activities in the same process (`InMemoryWorkflowRunner`, **`drainActivityQueueOnce`**, **`ExecutionRuntime`** with **`$distributed === false`** where applicable) — see [ADR007](ADR007-workflow-recovery.md).
- **Bundle (Messenger)**: activities consumed by **`ActivityRunHandler`** via **`messenger:consume`** on the activity transport; workflows consumed by **`WorkflowRunHandler`** on a dedicated transport; shared **EventStore** and optionally **resume metadata** (Dbal) — see [ADR007](ADR007-workflow-recovery.md) and [ADR009](ADR009-distributed-workflow-dispatch.md).

References
---

- [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
- [ADR004 - Ports and Adapters](ADR004-ports-and-adapters.md)
- [ADR007 - Recovery and replay](ADR007-workflow-recovery.md)
- [ADR009 - Workflow re-dispatch](ADR009-distributed-workflow-dispatch.md)
- [WA002 - Messenger transports vs EventStore engine](../wa/WA002-messenger-transports-and-event-store-engine.md) — DSN des files (`durable_workflows`, etc.) indépendante du backend journal Durable (DBAL vs Temporal)
