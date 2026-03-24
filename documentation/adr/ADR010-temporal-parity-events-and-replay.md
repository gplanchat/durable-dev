# ADR010 — Événements et replay pour la parité Temporal (workflows)

ADR010-temporal-parity-events-and-replay
===

Introduction
---

Ce **Architecture Decision Record** inventorie les **types d’événements** et le comportement au **replay** nécessaires pour couvrir les capacités workflow documentées dans [OST004](../ost/OST004-workflow-temporal-feature-parity.md) (alignement avec le SDK PHP Temporal).

Contexte
---

[OST004](../ost/OST004-workflow-temporal-feature-parity.md) liste cinq familles : side effects, timers durables, workflows enfants, continue-as-new, signaux / queries / updates. Le moteur Durable repose sur un **journal append-only** et des **slots séquentiels** par famille d’opérations (voir [ADR007](ADR007-workflow-recovery.md)).

Décision — inventaire par capacité
---

### 1. Side effects

| Élément | Décision |
|---------|----------|
| **Événements** | `SideEffectRecorded(executionId, sideEffectId, result)` — un événement par appel réussi. |
| **Replay** | Ordre des `SideEffectRecorded` dans le flux = ordre des appels `ExecutionContext::sideEffect()` ; le *slot* est l’index dans cette sous-séquence. La closure **n’est pas** ré-exécutée ; `result` est relu depuis le journal. |
| **Échec** | Exception propagée depuis la closure **avant** append → aucun événement ; aligné sur Temporal (échec de la tâche workflow). |
| **API** | `WorkflowEnvironment::sideEffect(Closure): mixed` (via await interne) ; équivalent sur `ExecutionContext` pour les handlers bas niveau. |

**Référence** : [Temporal — Side Effects (PHP)](https://docs.temporal.io/develop/php/side-effects).

### 2. Timers durables

| Élément | Décision |
|---------|----------|
| **Événements** | Déjà : `TimerScheduled`, `TimerCompleted` (inchangé). |
| **Replay** | Slot sur la sous-séquence `TimerScheduled` + complétion par `TimerCompleted` (comportement actuel de `delay()`). |
| **API** | `WorkflowEnvironment::timer($seconds)` = alias de `delay()` ; aligné sur la doc Temporal. |

**Référence** : [Temporal — Durable Timers (PHP)](https://docs.temporal.io/develop/php/timers).

### 3. Workflows enfants (child workflows)

| Élément | Décision |
|---------|----------|
| **Événements** | `ChildWorkflowScheduled` (incl. `parentClosePolicy`, `requestedWorkflowId`), `ChildWorkflowCompleted`, `ChildWorkflowFailed` (message/code + champs optionnels alignés sur `WorkflowExecutionFailed` enfant lorsque projetés depuis l’async Messenger). |
| **Replay** | Slot sur la sous-séquence `ChildWorkflowScheduled` ; résolution par `ChildWorkflowCompleted` ou `ChildWorkflowFailed` pour le même `childExecutionId`. L’exception **`DurableChildWorkflowFailedException`** au parent reprend les champs enrichis du journal au replay. |
| **Exécution** | **Inline** : `ChildWorkflowRunner` + `InMemoryWorkflowRunner` sur le journal enfant. **Async Messenger** (`distributed` + `child_workflow.async_messenger`) : dispatch `WorkflowRunMessage`, finalisation parent dans `WorkflowRunHandler`, persistance du lien parent↔enfant via **`ChildWorkflowParentLinkStoreInterface`** (in_memory ou DBAL). |
| **API** | `WorkflowEnvironment::executeChildWorkflow` / **`childWorkflowStub(ChildClass::class)`** ; options `ChildWorkflowOptions`. Voir [ADR011](ADR011-child-workflow-continue-as-new.md). |

**Référence** : [Temporal — Child Workflows (PHP)](https://docs.temporal.io/develop/php/child-workflows).

### 4. Continue-as-new

| Élément | Décision (cible) |
|---------|------------------|
| **Événements** | Marqueur de fin de run + démarrage d’un nouveau run (même `executionId` logique ou politique de chaînage explicite) ; historique du nouveau run **vide** au sens replay. |
| **Replay** | Un run ne rejoue que **son** segment d’historique ; pas de mélange avec l’historique du run précédent. |
| **Handlers** | Règle Temporal : ne pas invoquer continue-as-new depuis des handlers Updates/Signals sans synchronisation — à répliquer dans la doc produit. |

**Référence** : [Temporal — Continue-As-New (PHP)](https://docs.temporal.io/develop/php/continue-as-new).

### 5. Signaux, queries, updates

| Élément | Décision (cible) |
|---------|------------------|
| **Événements** | `WorkflowSignalReceived`, `WorkflowUpdateHandled` (journal) ; queries = lecture journal via **`WorkflowQueryEvaluator`** / **`WorkflowQueryRunner`** (pas d’événement d’historique équivalent *QueryCompleted* côté parent). |
| **Replay** | Les signaux / updates acceptés font partie du journal déterministe ; les queries ne doivent pas produire de commandes (pas d’activité / timer depuis le handler). |
| **API** | Attributs PHP miroir (évolution de [OST003](../ost/OST003-activity-api-ergonomics.md)) + client / transport. |

**Référence** : [Temporal — Message passing (PHP)](https://docs.temporal.io/develop/php/message-passing).

Synthèse — état d’implémentation
---

| Capacité | Événements / API | Statut |
|----------|------------------|--------|
| Side effects | `SideEffectRecorded` + `sideEffect()` | **Implémenté** (ce ADR) |
| Timers | `TimerScheduled` / `TimerCompleted` + `timer()` | **Timers** OK ; alias `timer()` |
| Child workflows | Événements ci-dessus + **`ChildWorkflowStub`** / `executeChildWorkflow` + async Messenger + **`DbalChildWorkflowParentLinkStore`** + projection d’échec riche (`AsyncChildWorkflowFailureProjector`) | **Partiel** (parité SDK Temporal / timeouts / toutes les policies — voir [OST004](../ost/OST004-workflow-temporal-feature-parity.md)) ; **noyau** journal + inline + async + rejeu exception parent **OK** |
| Continue-as-new | `WorkflowContinuedAsNew` + `ContinueAsNewRequested` + `WorkflowRunHandler` | **Partiel** (nouvel `executionId`) |
| Signals / Queries / Updates | `WorkflowSignalReceived`, `WorkflowUpdateHandled`, `waitSignal` / `waitUpdate`, `DeliverWorkflowSignalMessage` / `DeliverWorkflowUpdateMessage` + handlers, `WorkflowQueryEvaluator` / `WorkflowQueryRunner` | **Partiel** (queries = lecture journal ; transport signal/update = Messenger + append + `dispatchResume`) |

Conséquences
---

- **Replay activité avec résultat `null`** : utiliser `array_key_exists` (et non `isset`) pour détecter un `ActivityCompleted` dans la carte des résultats au replay ; sinon une activité dont le résultat est `null` est traitée comme non terminée (risque de re-planification / boucle de suspension).
- Tout nouveau type d’événement persistant doit être enregistré dans `EventSerializer` (sérialisation Dbal / replay).
- Les tests de workflows distribués qui comparent le journal événement par événement doivent étendre les contraintes (ex. `DistributedWorkflowJournalEquivalentConstraint`) lorsque de nouveaux types sont utilisés dans les scénarios attendus.
- [PRD001](../prd/PRD001-current-component-state.md) porte la matrice « Temporal ↔ Durable » à jour pour les équipes produit.

Références
---

- [OST004 — Parité Temporal](../ost/OST004-workflow-temporal-feature-parity.md)
- [ADR007 — Workflow recovery](ADR007-workflow-recovery.md)
- [ADR009 — Distributed workflow dispatch](ADR009-distributed-workflow-dispatch.md)
- [PRD001 — État actuel](../prd/PRD001-current-component-state.md)
