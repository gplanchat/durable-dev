# État actuel du composant Durable

PRD001-current-component-state
===

Introduction
---

Ce **Product Requirements Document** décrit l’état du composant et du bundle **Durable** après la refonte « workflows en classe » (`#[Workflow]` / `#[WorkflowMethod]`, `WorkflowEnvironment`, `ActivityStub`, contrats d’activité) et les extensions mode distribué (Messenger, lien parent↔enfant persisté, projection d’échec enfant sur le journal parent).

Objectifs
---

Fournir une bibliothèque PHP et un bundle Symfony pour des **exécutions durables** : orchestration déterministe (replay depuis un journal), activités asynchrones, alignement conceptuel avec Temporal.io, **sans** dépendance à RoadRunner ni au SDK Temporal.

Spécifications fonctionnelles
---

### Workflows

- **Modèle principal** : classes annotées `#[Workflow('TypeName')]` avec une méthode `#[WorkflowMethod]` ; le moteur injecte **`WorkflowEnvironment`** (await, activités typées, enfant, timers, signaux, etc.).
- **Modèle bas niveau** : un `callable(WorkflowEnvironment $env): mixed` peut toujours être enregistré ou utilisé pour des tests harness.
- **Activités** : via **`ActivityStub`** et interfaces marquées `#[ActivityMethod]` ; résolution des noms d’activité via **`activity_contracts`** (bundle) et **`ActivityContractResolver`**.
- **Timers** : `WorkflowEnvironment` / contexte — `delay()` / `timer()` ; journal `TimerScheduled` / `TimerCompleted` ; en distribué, **`FireWorkflowTimersMessage`** + handler pour faire progresser les timers et re-dispatcher la reprise.
- **Side effects** : résultat dans `SideEffectRecorded`, non ré-exécuté au replay ([ADR010](../adr/ADR010-temporal-parity-events-and-replay.md)).
- **Workflows enfants** : `executeChildWorkflow` ou **`childWorkflowStub()`** ; options `ChildWorkflowOptions` (`workflowId`, `parentClosePolicy`) ; journal parent `ChildWorkflowScheduled` / `ChildWorkflowCompleted` / **`ChildWorkflowFailed`** (champs enrichis : kind / classe / contexte d’échec workflow enfant lorsque projetés depuis le journal enfant) ; coordinateur parent/enfant ; `WorkflowCancellationRequested` si *request cancel* ([ADR010](../adr/ADR010-temporal-parity-events-and-replay.md), [ADR009](../adr/ADR009-distributed-workflow-dispatch.md)).
- **Enfant async Messenger** : avec `distributed: true` et `child_workflow.async_messenger: true`, le **`ChildWorkflowRunner`** dispatche un **`WorkflowRunMessage`** ; **`WorkflowRunHandler`** finalise le parent (`ChildWorkflowCompleted` / `ChildWorkflowFailed`) et utilise le **`ChildWorkflowParentLinkStoreInterface`** (**in_memory** ou **DBAL** multi-instance).
- **Continue-as-new** : `WorkflowContinuedAsNew` + `ContinueAsNewRequested` ; handler Messenger enchaîne un nouveau `executionId` ([ADR009](../adr/ADR009-distributed-workflow-dispatch.md)).
- **Signaux / updates** : `waitSignal` / `waitUpdate` ; livraison via Messenger + handlers ; `WorkflowSuspendedException::shouldDispatchResume()` géré pour éviter boucles sync ; **queries** : `WorkflowQueryEvaluator` / `WorkflowQueryRunner`.
- **Parallélisme** : `parallel()`, `all()`, `any()`, `race()` (fonctions ou équivalents via l’environnement).
- **Replay** : ordre des **slots** (activités, timers, enfants, signaux, …) reconstruit depuis le journal.
- **Exception parent** : en cas d’échec enfant observé sur le journal parent, **`DurableChildWorkflowFailedException`** expose en rejeu les champs alignés sur **`ChildWorkflowFailed`** (`workflowFailureKind`, `workflowFailureClass`, `workflowFailureContext`) lorsqu’ils sont présents dans le journal.

### Event Store

- Interface `EventStoreInterface` : `append(Event)`, `readStream(executionId)`
- Événements : jeu complet incluant `ChildWorkflowFailed` (payload enrichi optionnel), `WorkflowExecutionFailed`, etc.
- Implémentations : **`DbalEventStore`**, **`InMemoryEventStore`**

### Transport des activités

- **`MessengerActivityTransport`**, **`DbalActivityTransport`**, **`InMemoryActivityTransport`**

### Activités

- **`RegistryActivityExecutor`** : enregistrement par nom (souvent aligné sur les méthodes de contrat `#[ActivityMethod]`)

### Bundle Symfony

- **`DurableBundle`** : moteur, runtime, **`WorkflowRegistry`** + **`WorkflowDefinitionLoader`** (tag `durable.workflow`), résolution des contrats d’activité, coordinateur parent/enfant, handlers Messenger (signaux, updates, timers, **`WorkflowRunHandler`**), **`ChildWorkflowParentLinkStoreInterface`**, **`WorkflowQueryRunner`**, commande **`durable:activity:consume`**
- Paramètres clés : `distributed`, `event_store`, `workflow_metadata`, `activity_transport`, `max_activity_retries`, **`child_workflow.async_messenger`**, **`child_workflow.parent_link_store`** (`type`: `in_memory` | `dbal`, `table_name`)
- Reprises : **`MessengerWorkflowResumeDispatcher`** + **`DispatchAfterCurrentBusStamp`**

### Application exemple `symfony/`

- Workflows samples (Temporal-like) en **`App\Durable\Workflow\`**, tag `durable.workflow`
- Config : journal + métadonnées + lien parent en **DBAL** en `dev` ; **`when@test`** repasse en in_memory pour d’éventuels tests kernel
- Commande **`durable:schema:init`** : création idempotente des tables DBAL Durable (journal, métadonnées, lien parent-enfant)

Critères d'acceptation
---

- [x] Workflows en classe + `WorkflowEnvironment` + `ActivityStub` + contrats `#[ActivityMethod]`
- [x] Workflow avec activités et replay (tests unitaires / intégration)
- [x] Transports Messenger, Dbal, InMemory
- [x] Timers + réveil distribué (`FireWorkflowTimersMessage`)
- [x] Side effects persistés et rejouables
- [x] Workflows enfants inline et async Messenger + journal parent + lien parent persistable (DBAL)
- [x] Projection d’échec enfant riche sur le journal parent + rejeu via `DurableChildWorkflowFailedException`
- [x] Continue-as-new, signaux / updates, queries journal
- [x] Parent close policy + id enfant explicite
- [x] Retries d’activités (options sur stubs / exécuteur)

État d'implémentation
---

| Composant | État | Notes |
|-----------|------|-------|
| ExecutionEngine | Implémenté | `WorkflowEnvironment`, option coordinateur / résolveurs |
| ExecutionContext | Implémenté | Slots, replay, activités, timers, side effects, enfant, signaux, updates |
| WorkflowEnvironment | Implémenté | Façade await / stubs / enfant |
| ChildWorkflowRunner | Implémenté | Inline (`InMemoryWorkflowRunner`) ou async Messenger |
| ExecutionRuntime | Implémenté | await, drain, `checkTimers` (horloge injectable) |
| EventStoreInterface | Implémenté | Dbal, InMemory |
| ChildWorkflowParentLinkStoreInterface | Implémenté | InMemory, **Dbal** (`createSchema`) |
| DurableBundle | Implémenté | DI, commandes, config étendue |
| Mode distribué (Messenger) | **Implémenté** | `WorkflowRunMessage`, reprises, activités via transport dédié |
| Temporal driver | Non implémenté | [OST001](../ost/OST001-future-opportunities.md) |

### Matrice parité Temporal ↔ Durable ([OST004](../ost/OST004-workflow-temporal-feature-parity.md))

*Pour le détail des écarts restants, voir [OST004](../ost/OST004-workflow-temporal-feature-parity.md). Résumé :*

| Fonctionnalité Temporal | État Durable | Notes |
|-------------------------|--------------|-------|
| Side effects | **Supporté** | |
| Durable timers | **Supporté** | + message `FireWorkflowTimers` en distribué |
| Child workflows | **Partiel → avancé** | Classes + journal + async + lien DBAL ; parité SDK / stub avancé encore partielle |
| Continue-as-new | **Partiel** | Nouvel `executionId` ; pas de même identité logique « run » que Temporal |
| Signals / Queries / Updates | **Partiel** | Journal + Messenger ; ergonomie client riche hors scope |

Références
---

- [INDEX.md](../INDEX.md)
- [ADR004 - Ports et Adapters](../adr/ADR004-ports-and-adapters.md)
- [ADR005 - Messenger](../adr/ADR005-messenger-integration.md)
- [ADR009 - Modèle distribué](../adr/ADR009-distributed-workflow-dispatch.md)
- [ADR010 - Parité Temporal, événements et replay](../adr/ADR010-temporal-parity-events-and-replay.md)
- [OST001 - Opportunités futures](../ost/OST001-future-opportunities.md)
- [OST004 - Parité fonctionnelle workflow / Temporal](../ost/OST004-workflow-temporal-feature-parity.md)
- README racine — exemple `symfony/config/packages/durable.yaml`
