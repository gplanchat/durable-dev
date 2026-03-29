# Activity patterns

ADR006-activity-patterns
===

Introduction
---

This **Architecture Decision Record** defines the mandatory patterns for implementing activities in the Durable project. Activities are units of work executed durably, potentially with retries and resume.

Principles
---

### Interface-first

Each activity **MUST** be defined via an interface before implementation. Parameters **MUST** be individual domain types, not composite DTOs.

```php
interface CreateNamespaceActivityInterface
{
    public function create(NamespaceInterface $namespace): NamespaceCreatedEvent|NamespaceCreationFailedEvent;
}
```

### Idempotence

Activities **MUST** be idempotent: re-execution with the same inputs must not produce additional side effects.

### Error handling

- **Business exceptions (non-retryable)**: `NotFoundException`, `ValidationException`, etc.
- **System exceptions (retryable)**: timeouts, network errors, temporary unavailability

Activities **MUST** clearly separate these two categories. Business exceptions may be configured as non-retryable in retry options.

### Dependency injection

Activities **MUST** receive dependencies via the constructor (dependency injection).

### Calling from a workflow

The workflow **MUST NOT** call the concrete activity implementation directly: it obtains a typed **`ActivityStub`** via **`WorkflowEnvironment::activityStub(ActivityInterface::class)`** then invokes the method annotated `#[ActivityMethod]` (see [ADR005](ADR005-messenger-integration.md), [OST003](../ost/OST003-activity-api-ergonomics.md), [ADR012](ADR012-activity-stub-metadata-and-static-analysis.md) for cache / warmup / PHPStan). The **interface-first** and **idempotence** rules above apply to implementations registered in `RegistryActivityExecutor` / transports.

Registration
---

Activities are registered via `RegistryActivityExecutor`:

```php
$executor->register('create_namespace', function (array $payload) {
    return $this->createNamespaceActivity->create(
        $this->namespaceMapper->fromPayload($payload)
    );
});
```

Retries
---

Retry strategy is configurable at worker level (`max_retries`). Failed activities are re-enqueued until attempts are exhausted, then an `ActivityFailed` event is persisted in the EventStore.

References
---

- [Temporal Activities](https://docs.temporal.io/activities)
- [HIVE042-META01 - Activity Implementation Guide](../../architecture/hive/HIVE042-temporal-workflows-implementation/HIVE042-META01-activity-implementation-guide.md)
- [ADR005 - Messenger integration](ADR005-messenger-integration.md)
