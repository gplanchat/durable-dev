# Audit du code — Phase 2

AUDIT001-phase2-code-review
===

Introduction
---

Ce rapport documente l'audit du code `src/` réalisé dans le cadre de la Phase 2 du plan de refonte Durable. Il vérifie la cohérence, la séparation des responsabilités et l'alignement avec les ADR.

Périmètre
---

- **Dossier** : `src/`
- **Date** : Phase 2
- **Référentiels** : ADR001–ADR009, architecture hive/runtime/compiler

Cohérence et séparation des responsabilités
---

### Composant (logique pure)

| Fichier / Dossier | Responsabilité | Dépendances | Conformité |
|-------------------|----------------|-------------|------------|
| `ExecutionEngine` | Démarrage des workflows | EventStoreInterface, ExecutionRuntime | OK — sans HttpKernel |
| `ExecutionContext` | Contexte d'exécution, replay, slots | EventStoreInterface, ActivityTransportInterface | OK |
| `ExecutionRuntime` | Boucle await, drain, timers | EventStoreInterface, ActivityTransportInterface, ActivityExecutor | OK |
| `Store/` | Persistance événements, métadonnées | Doctrine (optionnel) | OK — ports définis |
| `Transport/` | Transport activités, messages workflow | Messenger (optionnel) | OK — ports définis |
| `Event/` | Événements de domaine | Aucune | OK |
| `Awaitable/` | Promises/Deferred | Aucune | OK |
| `Port/` | Interfaces (WorkflowBackend, WorkflowResumeDispatcher) | Aucune | OK |
| `WorkflowRegistry` | Enregistrement workflows par type | Aucune | OK |

### Bundle (intégration Symfony)

| Fichier / Dossier | Responsabilité | Conformité |
|-------------------|----------------|------------|
| `DurableBundle` | Enregistrement du bundle | OK |
| `DependencyInjection/` | Configuration DI, paramètres | OK |
| `Command/ActivityWorkerCommand` | Consommation activités | OK |
| `Handler/WorkflowRunHandler` | Exécution workflows (Messenger) | OK |
| `Messenger/MessengerWorkflowResumeDispatcher` | Re-dispatch workflow | OK |

### Ports et Adapters (ADR004)

- **EventStoreInterface** / **ActivityTransportInterface** : Ports canoniques utilisés partout
- **WorkflowBackendInterface** : Port pour backends (LocalWorkflowBackend implémenté)
- **WorkflowResumeDispatcher** : Port pour re-dispatch (Null + Messenger)

Tests
---

- 19 tests PHPUnit, 57 assertions — tous verts
- Couverture : unit (FunctionsTest, Awaitable), intégration (Bundle, Messenger, Dbal, Maquette)
- Conformité ADR003 : usage de InMemoryEventStore, InMemoryActivityTransport, pas de mocks excessifs

Points d'attention
---

1. **Timers en mode distribué** : `delay()` avec `distributed=true` lève `WorkflowSuspendedException` — le mécanisme de réveil (table timers, cron) n'est pas encore implémenté (OST001).
2. **WorkflowRunHandler** : Nécessite `messenger.default_bus` lorsque `distributed=true` — l'app doit configurer Messenger.
3. **Interfaces** : `EventStoreInterface` et `ActivityTransportInterface` sont les seuls identifiants de service.

Conclusion
---

Le code est **cohérent** avec les ADR et la séparation composant/Bundle est respectée. Les ports sont clairement identifiés. Phase 2 considérée comme complète.

Références
---

- [ADR004 - Ports et Adapters](../adr/ADR004-ports-and-adapters.md)
- [ADR009 - Modèle distribué](../adr/ADR009-distributed-workflow-dispatch.md)
- [PRD001 - État actuel](../prd/PRD001-current-component-state.md)
