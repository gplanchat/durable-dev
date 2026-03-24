# Modèle distribué et re-dispatch des workflows

ADR009-distributed-workflow-dispatch
===

Introduction
---

Ce **Architecture Decision Record** définit le modèle d'exécution distribué pour les workflows du projet Durable, où le workflow et les activités s'exécutent dans des process séparés. Il complète [ADR007 - Reprise et recovery](ADR007-workflow-recovery.md) en détaillant l'implémentation du re-dispatch.

Contexte
---

En mode **inline**, le workflow et les activités s'exécutent dans le même process via **`drainActivityQueueOnce`**. Pour la scalabilité horizontale, le mode **distribué** (`durable.distributed: true`) permet que :

- Les activités soient exécutées par des workers dédiés (`durable:activity:consume`)
- Le workflow « sorte » après avoir planifié une activité ou un timer (suspension)
- Le workflow soit **re-dispatché** lorsque l’activité se termine, qu’un timer est réveillé, ou qu’un signal/update est livré

Les workflows applicatifs sont des **classes** `#[Workflow]` : le handler invoqué par le moteur est **`callable(WorkflowEnvironment $env): mixed`** (obtenu via **`WorkflowRegistry::getHandler($type, $payload)`** après **`registerClass`** ou tag Symfony `durable.workflow`).

Principe du re-dispatch
---

1. **Démarrage** : Un message **`WorkflowRunMessage`** est dispatché avec `executionId`, `workflowType`, `payload`
2. **Exécution** : **`WorkflowRunHandler`** charge les métadonnées, obtient le handler depuis le registry, appelle **`ExecutionEngine::start`** ou **`resume`**. Au premier **`await`** sur une activité ou un timer non encore résolu en mode distribué, le runtime lève **`WorkflowSuspendedException`** et le handler **retourne** après avoir demandé une reprise (selon le type d’attente).
3. **Activité** : Un worker consomme le message d’activité, l'exécute, append **`ActivityCompleted`** à l'EventStore, puis **`dispatchResume($executionId)`**
4. **Timers** : un process (cron, handler synchrone, etc.) dispatche **`FireWorkflowTimersMessage`** ; le handler appelle **`checkTimers`** et **`dispatchResume`** si des timers sont passés complétés
5. **Reprise** : Un nouveau **`WorkflowRunMessage`** (reprise) est traité ; le moteur **rejoue** depuis l'EventStore via les slots du **`ExecutionContext`** exposés au **`WorkflowEnvironment`**

### Continue-as-new

Si le workflow appelle **`WorkflowEnvironment::continueAsNew($workflowType, $payload)`** (qui délègue au contexte), le moteur append **`WorkflowContinuedAsNew`** sur le run courant (sans **`ExecutionCompleted`**) et lève **`ContinueAsNewRequested`**. Le **`WorkflowRunHandler`** supprime les métadonnées de l’ancien `executionId`, en enregistre pour un **nouvel** identifiant, et dispatche un **`WorkflowRunMessage`** de démarrage (`dispatchNewWorkflowRun`) avec le type et le payload du run suivant.

Prérequis
---

- **`WorkflowRegistry`** : enregistrement par classe **`#[Workflow]`** (`registerClass`) ou compilation Symfony
- **`WorkflowMetadataStore`** : persiste `(executionId, workflowType, payload)` au démarrage pour la reprise entre messages
- **`WorkflowResumeDispatcher`** : injectée dans le worker d’activités et les handlers de contrôle (timers, signaux, updates)

Transports
---

- **Activités** : transport configuré (ex. `durable_activities`) — messages **`ActivityMessage`**
- **Workflows** : transport dédié (ex. `durable_workflows`) — messages **`WorkflowRunMessage`**
- **Timers (réveil)** : message **`FireWorkflowTimersMessage`** — à router (ex. sync / cron) vers **`FireWorkflowTimersHandler`** ; append **`TimerCompleted`** + `dispatchResume` si au moins un timer est devenu dû
- **Reprises** : **`MessengerWorkflowResumeDispatcher`** utilise **`DispatchAfterCurrentBusStamp`** pour ne pas empiler des **`WorkflowRunMessage`** en récursion synchrone pendant le handler courant

Configuration
---

```yaml
durable:
    distributed: true
```

Le nom du transport Messenger pour les workflows est défini dans **`framework.messenger.routing`** (voir application exemple `symfony/config/packages/messenger.yaml`). La clé **`workflow_transport`** du bundle, si présente, documente la convention de nommage ; le câblage effectif reste côté **`framework`**.

Lorsque **`distributed: false`** (défaut), le comportement reste le mode inline (pas de **`WorkflowRunHandler`** sur Messenger pour le corps du run).

Concurrence `any()` / `race()`
---

Lorsqu’une compétition d’activités se termine (premier **`Awaitable`** réussi ou rejeté), les activités **encore en file** et **non consommées** sont **retirées du transport** (best effort) : **`ActivityTransportInterface::removePendingFor()`**. Un événement **`ActivityCancelled`** est append avec la raison `race_superseded`, et le slot correspondant rejoue une **`ActivitySupersededException`**.

- **In-memory / DBAL** : retrait effectif du message en attente.
- **Messenger** : pas de retrait fiable sans API dédiée — retour `false`, l’activité peut encore s’exécuter (comportement acceptable).
- Si le worker a **déjà vidé toute la file** avant la reprise du workflow, les activités perdantes peuvent déjà être **`ActivityCompleted`** : aucune annulation n’est alors possible, l’historique reste cohérent.

Suspension signal / update vs activité / timer
---

**`WorkflowSuspendedException`** porte **`shouldDispatchResume()`** : en mode distribué, une attente **activité** ou **timer** (`ActivityAwaitable`, `TimerAwaitable`, y compris dans `any()` / `CancellingAnyAwaitable`) provoque un **`dispatchResume`** depuis **`WorkflowRunHandler`** (le worker activité ou le réveil timer peut faire progresser le run). Une attente **signal** ou **update** ne déclenche **pas** ce re-dispatch automatique : sinon, avec un transport Messenger **sync**, la reprise récursive bouclerait sans fin. La reprise est alors assurée par **`DeliverWorkflowSignalMessage`** / **`DeliverWorkflowUpdateMessage`** (append journal + **`dispatchResume`**).

Limitations connues
---

- Les **timers** en mode distribué nécessitent qu’un process dispatche **`FireWorkflowTimersMessage`** (ou équivalent) pour que **`checkTimers`** puisse append **`TimerCompleted`** avant la reprise utile du run
- Le **type** de workflow et le **payload** doivent être reproductibles : le registry résout le handler à partir du type string et du payload stockés en métadonnées

Références
---

- [ADR007 - Reprise et recovery](ADR007-workflow-recovery.md)
- [ADR005 - Intégration Messenger](ADR005-messenger-integration.md)
- [PRD001 - État actuel](../prd/PRD001-current-component-state.md)
