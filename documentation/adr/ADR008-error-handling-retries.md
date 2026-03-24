# Gestion des erreurs et retries

ADR008-error-handling-retries
===

Introduction
---

Ce **Architecture Decision Record** définit la stratégie de gestion des erreurs et des retries pour les activités et workflows du projet Durable. Elle couvre la persistance des échecs, la restauration au `await()`, les échecs non sérialisables (catastrophiques) et le contrat « workflow doit gérer les erreurs d’activité ».

Trois cas à l’émission d’une exception dans une activité (après épuisement des retries)
---

1. **Récupération dans le workflow** : un bloc `try / catch` autour de `await()` absorbe l’exception ; le journal contient `ActivityFailed` (ou `ActivityCatastrophicFailure` si non sérialisable) mais **pas** `WorkflowExecutionFailed`.
2. **Échec sérialisable non attrapé** : l’exception est représentée dans l’event store (`ActivityFailed` + `FailureEnvelope`). Au `await()` / replay, une exception est relancée :
   - si elle implémente `DeclaredActivityFailureInterface`, **restauration** via `restoreFromActivityFailureContext()` ;
   - sinon **`DurableActivityFailedException`** avec `activityId`, `activityName`, `attempt`, trace, contexte et **chaîne `previous`** reconstituée (`ActivityFailureCauseException`).
3. **Échec non sérialisable** : payload JSON impossible (ex. contexte déclaré invalide) → **`ActivityCatastrophicFailure`** dans le journal et **`DurableCatastrophicActivityFailureException`** au `await()`. Traiter comme **défaillance grave** du code / des données.

Workflow et intégration : échec non géré
---

Si une erreur d’activité (`DurableActivityFailedException`, `DeclaredActivityFailureInterface`, `DurableCatastrophicActivityFailureException`) **remonte hors du handler** sans `try / catch`, `ExecutionEngine` :

- append **`WorkflowExecutionFailed`** (kind adapté) ;
- relance **`DurableWorkflowAlgorithmFailureException`** avec `getPrevious()` = l’exception d’activité.

Cela matérialise une **défaillance d’algorithme / d’intégration** : le workflow devait anticiper l’échec.

**Suspension** : `WorkflowSuspendedException` n’est **pas** un échec : elle est propagée sans `WorkflowExecutionFailed`.

Identification de la source
---

- Chaque **`ActivityScheduled`** lie un `activityId` stable ; **`ActivityFailed`** / **`ActivityCatastrophicFailure`** répètent `activityId`, `activityName`, `failureAttempt` (tentative au moment de l’échec).
- **`FailureEnvelope`** inclut la **trace** de l’exception racine et une **`previousChain`** sérialisée `{ class, message, code }[]` pour les causes `getPrevious()`.
- **`DurableActivityFailedException`** expose `envelope()` pour logs / traces sans relire le store.

Classification des erreurs (rappel)
---

### Erreurs métier (non-retryable)

- **NotFoundException** : ressource absente
- **ValidationException** : données invalides
- **BusinessLogicException** : règle métier violée
- **DuplicateResourceException** : conflit (ex. déjà créé)

Ces erreurs ne doivent **pas** être retentées : une nouvelle tentative produirait le même échec. Pour les faire voyager de façon déterministe, préférer **`DeclaredActivityFailureInterface`**.

### Erreurs système (retryable)

- Timeouts réseau
- Indisponibilité temporaire (503, connexion refusée)
- Deadlocks
- OutOfMemory (avec restart du worker)

Retries
---

- **Configuration** : `max_retries` au niveau du worker (`ActivityWorkerCommand`, `ExecutionRuntime`)
- **Comportement** : si `message->attempt() <= maxRetries`, le message est ré-enqueueé avec `withAttempt(attempt + 1)`
- **Épuisement** : `ActivityFailureEventFactory::fromActivityThrowable()` produit `ActivityFailed` ou `ActivityCatastrophicFailure`

Exponential backoff (évolution possible)
---

Une évolution future pourrait introduire un délai entre les retries (exponential backoff) :
- Délai = `initialDelay * (multiplier ^ attempt)`
- Configurable par activité ou globalement

Logging
---

- Logger avec : `executionId`, `activityId`, `activityName`, `attempt`, `failureClass`, extrait de message (sans données sensibles)
- Les exceptions métier ne doivent pas exposer de données sensibles dans les messages ni dans `toActivityFailureContext()`

Références
---

- [RUNTIME-RFC004 - Error Handling](../../architecture/runtime/rfcs/RUNTIME-RFC004-error-handling-logging.md)
- [ADR006 - Patterns activité](ADR006-activity-patterns.md)
- [src/Port/DeclaredActivityFailureInterface.php](../../src/Port/DeclaredActivityFailureInterface.php)
- [src/Failure/FailureEnvelope.php](../../src/Failure/FailureEnvelope.php)
- [src/Failure/ActivityFailureEventFactory.php](../../src/Failure/ActivityFailureEventFactory.php)
- [src/Event/ActivityFailed.php](../../src/Event/ActivityFailed.php)
- [src/Event/ActivityCatastrophicFailure.php](../../src/Event/ActivityCatastrophicFailure.php)
- [src/Event/WorkflowExecutionFailed.php](../../src/Event/WorkflowExecutionFailed.php)
- [src/Exception/DurableActivityFailedException.php](../../src/Exception/DurableActivityFailedException.php)
- [src/Exception/DurableWorkflowAlgorithmFailureException.php](../../src/Exception/DurableWorkflowAlgorithmFailureException.php)
