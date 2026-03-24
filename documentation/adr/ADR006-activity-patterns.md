# Patterns d'activités

ADR006-activity-patterns
===

Introduction
---

Ce **Architecture Decision Record** définit les patterns obligatoires pour l'implémentation des activités dans le projet Durable. Les activités sont les unités de travail exécutées de manière durable, potentiellement avec retries et reprise.

Principes
---

### Interface-first

Chaque activité _DOIT_ être définie via une interface avant l'implémentation. Les paramètres _DOIVENT_ être des types domaine individuels, pas des DTOs composites.

```php
interface CreateNamespaceActivityInterface
{
    public function create(NamespaceInterface $namespace): NamespaceCreatedEvent|NamespaceCreationFailedEvent;
}
```

### Idempotence

Les activités _DOIVENT_ être idempotentes : une nouvelle exécution avec les mêmes entrées ne doit pas produire d'effets de bord supplémentaires.

### Gestion des erreurs

- **Exceptions métier (non-retryable)** : `NotFoundException`, `ValidationException`, etc.
- **Exceptions système (retryable)** : timeouts, erreurs réseau, indisponibilité temporaire

Les activités _DOIVENT_ distinguer clairement ces deux catégories. Les exceptions métier peuvent être configurées comme non-retryable dans les options de retry.

### Injection de dépendances

Les activités _DOIVENT_ recevoir leurs dépendances via le constructeur (injection de dépendances).

### Appel depuis un workflow

Le workflow _NE DOIT PAS_ appeler directement l’implémentation concrète de l’activité : il obtient un **`ActivityStub`** typé via **`WorkflowEnvironment::activityStub(InterfaceActivité::class)`** puis invoque la méthode annotée `#[ActivityMethod]` (voir [ADR005](ADR005-messenger-integration.md), [OST003](../ost/OST003-activity-api-ergonomics.md)). Les règles **interface-first** et **idempotence** ci-dessus s’appliquent aux implémentations enregistrées dans `RegistryActivityExecutor` / transports.

Enregistrement
---

Les activités sont enregistrées via `RegistryActivityExecutor` :

```php
$executor->register('create_namespace', function (array $payload) {
    return $this->createNamespaceActivity->create(
        $this->namespaceMapper->fromPayload($payload)
    );
});
```

Retries
---

La stratégie de retry est configurable au niveau du worker (`max_retries`). Les activités échouées sont ré-enqueueées jusqu'à épuisement des tentatives, puis un événement `ActivityFailed` est persisté dans l'EventStore.

Références
---

- [Temporal Activities](https://docs.temporal.io/activities)
- [HIVE042-META01 - Activity Implementation Guide](../../architecture/hive/HIVE042-temporal-workflows-implementation/HIVE042-META01-activity-implementation-guide.md)
- [ADR005 - Intégration Messenger](ADR005-messenger-integration.md)
