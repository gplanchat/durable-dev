## 1. Probe the server before encoding any rule

- [x] 1.1 Probe Nexus endpoint, service and operation name rules (empty, blank, edge whitespace, control characters, length, case) against a local dev server, as was done for `TaskQueue`, `WorkflowNamespace` and `CronSchedule` — **endpoint**: server-enforced `^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$`, 200 chars, refused at creation. **service and operation**: the server validates neither and records them verbatim, so they need `TaskQueue`-style strictness while the endpoint needs none. Pinned by `NexusEndpointNameRulesTest` and `NexusServiceAndOperationNameRulesTest`; the three rules are tabulated in `design.md`
- [x] 1.2 Probe what the server does when scheduling on an unknown endpoint, and record the error shape — gRPC `INVALID_ARGUMENT`, `BadScheduleNexusOperationAttributes: endpoint "…" not found`, `WORKFLOW_TASK_FAILED` with cause `BAD_SCHEDULE_NEXUS_OPERATION_ATTRIBUTES`, and the task is retried without end. No typed failure reaches the workflow; pinned by `NexusUnknownEndpointTest`, consequence recorded in `design.md`
- [x] 1.3 Probe whether the three operation bounds behave like the activity ones, including any silent rewrite — **yes, and the rewrite exists**: a sub-bound larger than `scheduleToClose` is clamped down to it without an error; a negative duration is refused with the field named; `scheduleToClose = 0` means unbounded and clamps nothing; omitted bounds stay absent. Pinned by `NexusOperationBoundsTest`, table in `design.md`
- [x] 1.4 Record every verdict in the value-object docblocks, and write no invariant that was not observed — les trois objets portent leurs verdicts : `NexusEndpoint` la regex et la limite du serveur, `NexusService` et `NexusOperationName` le fait qu'il n'en valide aucun, `NexusOperationTimeouts` le rabot silencieux. Aucune borne de longueur ni alphabet n'est imposé aux deux noms : le serveur n'en a montré aucun

## 2. Domain value objects

- [x] 2.1 `NexusEndpoint`, `NexusService`, `NexusOperationName` — named constructors, boundary coercion, validation limited to probed rules — **`NexusEndpoint` fait** : motif et limite du serveur, ni plus ni moins, avec la distinction vide/malformé qu'il fait lui-même. `NexusService` et `NexusOperationName` attendent la moitié service/opération de 1.1 ; `NexusService` et `NexusOperationName` **faits** : plus stricts que le serveur comme `TaskQueue`, puisqu'il n'en valide aucun et que la faute y est muette
- [x] 2.2 `NexusOperationTimeouts` built on `Duration`, mirroring `ActivityTimeouts`, with `executionBoundOr()` if the server requires a closing bound — **no `executionBoundOr()`**: §1.3 measured that the server requires no closing bound (a command with none of the three is accepted and records none), so the condition does not hold. The object is stricter than the server on the one thing the server does silently: a sub-bound exceeding `schedule-to-close` is refused at construction instead of being clamped without a word. No heartbeat bound — a Nexus operation is served elsewhere and gives no intermediate sign of life
- [x] 2.3 Unit tests asserting the probed verdicts, one case per observation — **fait pour `NexusEndpoint`** : 20 cas, un par verdict du tableau de `design.md`, y compris la lettre seule que le motif refuse ; 26 cas de plus pour `NexusService` et `NexusOperationName`, refus **et** acceptations — un nom à point ou à barre oblique doit rester valide

## 3. Caller-side domain plumbing

- [x] 3.1 `NexusOperationAwaitable` carrying the operation identity, so the fiber driver can cancel it — même forme qu'`ActivityAwaitable`, `inner()` compris parce que `AwaitableCancellation` et les composites descendent par lui. L'annulation elle-même reste à câbler : elle a besoin du `cancelScheduledNexusOperation()` de §3.2
- [x] 3.2 `nexusOperationSlotIndex` in `ExecutionContext`, plus `scheduleNexusOperation()` on the environment — `nexusOperation()` des deux côtés (même verbe que `activity()`, plutôt que `scheduleNexusOperation()` qui aurait juré avec lui) ; coercition des trois noms à la frontière de l'environnement, registre `pendingNexusOperations` en miroir de celui des activités
- [x] 3.3 `findNexusOperationSlotResult()` and `findScheduledNexusOperation()` on `WorkflowHistorySourceInterface` — la source journal rend `null` **définitivement** (son tampon refuse d'écrire, il n'y a rien à relire) ; la source Temporal lève un `LogicException` nommant §4.3, parce que rendre `null` là ferait replanifier l'opération à chaque replay, en silence
- [x] 3.4 `scheduleNexusOperation()` and `cancelNexusOperation()` on `WorkflowCommandBufferInterface` — le backend journal **refuse** avec `NexusUnsupportedByBackendException`, ce que la proposition exige ; le tampon Temporal lève un `LogicException` nommant §4.1 / §4.2, pas l'exception « backend sans route » qui serait un mensonge sur ce que Temporal sait faire
- [x] 3.5 Extend `WorkflowFiberDriver::cancelPending()` to cancel a pending Nexus operation on workflow cancellation — rien à étendre dans le pilote : la marche unique est `AwaitableCancellation`, que le pilote et les composites partagent depuis leur consolidation, et c'est elle qui descend, composites traversés
- [x] 3.6 `DurableNexusOperationFailedException` with its four kinds, and its classification in `WorkflowFailureClassifier` — natures dans `NexusOperationFailureKind`, prises mot pour mot du spec ; le triplet endpoint/service/opération voyage dans le contexte de `KIND_UNHANDLED_NEXUS_OPERATION` ; le comportement de reprise n'est porté que par `HandlerError`

## 4. Temporal backend

- [~] 4.1 Build `ScheduleNexusOperation` in `TemporalWorkflowCommandBuffer`, bounds and headers included — **commande et bornes faites et prouvées contre un vrai serveur** ; les en-têtes sont **reportées** dans le change `nexus-operation-headers`. Rien côté domaine n'en porte, et le port de §3.4 devrait les transporter : les ajouter ici sans source serait un champ vide déguisé en fonctionnalité
- [x] 4.2 Build `RequestCancelNexusOperation` using the real scheduled event id read from history — débloquée par 4.3 ; une opération absente de l'historique n'émet rien, un eventId inventé faisant rejeter la tâche entière
- [x] 4.3 Read the nine `NEXUS_OPERATION_*` events in `TemporalExecutionHistory`, keyed by scheduled event id — planification (identité relue du payload d'entrée, faute de champ dédié côté Temporal) et les quatre états terminaux. Les trois événements d'annulation et `STARTED` restent hors périmètre (§4.5)
- [x] 4.4 Convert those events in `TemporalEventConverter` so the profiler and the read-through store show them — cinq événements de domaine (`NexusOperationScheduled` et les quatre états terminaux), rattachés par l'`eventId` de la planification, qui est la clé dont Temporal se sert lui-même. `NEXUS_OPERATION_STARTED` et les trois événements d'annulation ne sont pas convertis : le premier relève de §4.5, les autres n'ajoutent rien au profil tant que l'annulation n'est pas construite (§4.2)
- [x] 4.5 Fail clearly when an operation reports `NEXUS_OPERATION_STARTED` with a token — asynchronous operations are out of scope for this increment — `NexusAsynchronousOperationUnsupportedException`, nommant l'opération. **Le jeton seul déclenche le refus** : un `STARTED` sans jeton est une opération synchrone en vol, et la refuser rejetterait le cas nominal

## 5. In-memory backend

- [x] 5.1 `EventStoreCommandBuffer::scheduleNexusOperation()` throws, naming the limitation and pointing at the Temporal backend — **livré par §3.4**, `NexusUnsupportedByBackendException::forBackend('journal')` ; la case était restée ouverte alors que le code était sur `main`
- [x] 5.2 A test asserting the harness fails fast rather than hanging — au niveau du **harnais**, et pas seulement du tampon : le refus doit traverser le moteur. Un test épingle qu'il tombe à la planification et qu'aucun `await()` n'est atteint, ce qui distingue « échoue vite » de « échoue après un délai »

## 6. Integration against a real server

- [x] 6.1 Document the endpoint prerequisite at the top of the test, as the search-attribute suite documents its two attributes — avec une différence assumée : le test **crée et supprime** son endpoint, un nom d'endpoint étant unique pour le cluster entier. L'équivalent manuel est donné pour qui veut reproduire à la main
- [x] 6.2 Schedule an operation and assert the round trip through history — la commande est construite **par le tampon du pont**, pas à la main : c'est ce qui prouve que §4.1 est acceptée par un vrai serveur. Trois cas — les trois noms et les trois bornes reviennent inchangés, l'entrée survit, et l'absence de borne reste une absence
- [x] 6.3 Assert cancellation reaches the server with the real scheduled event id — le tampon relit l'historique de la tâche courante ; un signal force la tâche où poser l'annulation, faute de worker servant l'endpoint
- [x] 6.4 Assert a failed operation surfaces to the workflow with its origin named — a révélé que §3.6 et §4.3 n'étaient pas reliés : la lecture rendait des `RuntimeException` nues, donc la branche Nexus du classificateur était morte. Le site d'appel est désormais retenu de `NEXUS_OPERATION_SCHEDULED`, seul événement qui le porte

## 7. Documentation

- [x] 7.1 ADR recording the caller-only scope, the backend asymmetry, and why the handler side is a separate change — **DUR036** (le doublon DUR035 qui avait motivé ce saut est depuis résolu : la plus récente des deux est devenue DUR037). L'ADR s'ouvre sur les quatre mesures serveur, parce que ce sont elles qui ont donné leur forme aux décisions
- [x] 7.2 Update `documentation/INDEX.md` — DUR036 y était déjà ; l'entrée était en revanche à réparer, `DUR035` désignant deux ADR distinctes

---

**Archivé le 2026-08-27**, une entrée reportée. Les en-têtes Nexus de §4.1 sortent dans le change
`nexus-operation-headers` : la spec publiée les dit `MAY`, et elles n'ont pas de consommateur tant
que le côté handler n'existe pas. Tout le reste est livré et vérifié contre un serveur réel.
