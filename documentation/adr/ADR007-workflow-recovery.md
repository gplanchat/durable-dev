# Stratégie de reprise et recovery des workflows

ADR007-workflow-recovery
===

Introduction
---

Ce **Architecture Decision Record** définit la stratégie de reprise des workflows du projet Durable lorsque l'exécution est interrompue ou échoue. Cette stratégie s'appuie sur l'event sourcing et le replay déterministe déjà implémentés.

Contexte
---

Les workflows durables doivent survivre aux :
- Crashes du process
- Redémarrages des workers
- Échecs temporaires des activités
- Timeouts et indisponibilités réseau

La reprise doit garantir la cohérence des données et l'absence de duplication des effets de bord (idempotence).

Stratégie actuelle : Event Sourcing et replay
---

### Principe

Le flux d'événements (`EventStore::readStream`) constitue le **checkpoint implicite** du workflow. À chaque reprise, le workflow est rejoué (replay) en lisant les événements dans l'ordre. Les résultats des activités déjà complétées sont récupérés depuis les événements `ActivityCompleted` / `ActivityFailed`, sans ré-exécution.

### Slots déterministes

Chaque opération durable (activité, timer, side effect, enfant, signal, update, …) est associée à un **slot** (index séquentiel par famille d’opérations) sur le **`ExecutionContext`**. En pratique, les workflows en classe passent par **`WorkflowEnvironment`** (`await` sur stubs d’activité, `timer` / `delay`, `sideEffect`, `executeChildWorkflow`, etc.), qui délègue au contexte.

Lors du replay, les méthodes `findReplay*ForSlot()` déterminent si le slot a déjà un résultat enregistré dans le journal. Pour les activités complétées avec un résultat **`null`**, le moteur utilise `array_key_exists` afin de ne pas confondre avec l’absence de complétion (voir [ADR010](ADR010-temporal-parity-events-and-replay.md)).

### Garanties

- **Déterminisme** : le code du workflow ne doit pas contenir d'I/O ou de sources non déterministes (random, date, etc.) **hors** des activités ou des **side effects** explicites
- **Side effects** : toute valeur non déterministe doit passer par **`WorkflowEnvironment::sideEffect()`** (ou `ExecutionContext::sideEffect()` pour un handler bas niveau) — résultat journalisé, pas de ré-exécution au replay — voir [ADR010](ADR010-temporal-parity-events-and-replay.md)
- **Idempotence** : les activités doivent être idempotentes

Mode distribué : re-dispatch du workflow
---

Pour un environnement où le workflow et les activités s’exécutent dans des process séparés, la reprise repose sur le **re-dispatch** du workflow après progression asynchrone (activité, timer, etc.). Voir [ADR009](ADR009-distributed-workflow-dispatch.md).

### Implémenté (Messenger)

1. **`WorkflowRunMessage`** : démarrage ou reprise d’un run (`WorkflowRunHandler` + **`WorkflowMetadataStore`** pour type/payload entre messages).
2. Sur **suspension** distribuée (`WorkflowSuspendedException` avec `shouldDispatchResume()`), **`MessengerWorkflowResumeDispatcher`** enfile une nouvelle reprise (souvent avec **`DispatchAfterCurrentBusStamp`**).
3. Les activités sortent via **`ActivityTransportInterface`** (ex. transport Messenger **`durable_activities`**).
4. **Timers** : message **`FireWorkflowTimersMessage`** + handler qui appelle **`ExecutionRuntime::checkTimers`** et re-dispatch si besoin.
5. Au **replay** sur le même `executionId`, le journal fournit les résultats déjà enregistrés.

### Mode inline (hors Messenger distribué)

Workflow et activités dans le même process (**`InMemoryWorkflowRunner`**, **`drainActivityQueueOnce`**). Pas de re-dispatch ; après crash, relancer le run rejoue depuis le journal.

### Évolutions possibles

- Durcissement opérationnel (idempotence des handlers, métriques, DLQ).
- Politiques de backoff / retry au niveau transport Messenger (hors bundle Durable).

Références
---

- [RUNTIME-RFC033 - Workflow Recovery](../../architecture/runtime/rfcs/RUNTIME-RFC033-workflow-recovery-and-resume-strategy.md)
- [ADR005 - Intégration Messenger](ADR005-messenger-integration.md)
- [PRD001 - État actuel](../prd/PRD001-current-component-state.md)
- [ADR010 - Parité Temporal, événements et replay](ADR010-temporal-parity-events-and-replay.md)
