# ADR014 — Temporal journal EventStore bridge (gRPC, sans SDK Temporal)

ADR014-temporal-journal-eventstore-bridge
===

Context
---

Le plan « Temporal comme couche de persistance » impose un **journal durable** sans **SDK Temporal PHP** (pas de RoadRunner). L’historique Durable (`EventStoreInterface`) doit pouvoir être porté par un **workflow Temporal** minimal, avec **worker PHP** basé sur **gRPC** et les stubs protobuf publiés (`roadrunner-php/roadrunner-api-dto`, issus des protos Temporal).

Décision
---

1. **Bridge Temporal** : code monorepo sous **`src/Bridge/Temporal`**, namespace **`Gplanchat\Bridge\Temporal`** ; publication Packagist **`gplanchat/durable-bridge-temporal`** (splitsh sur le préfixe **`src/Bridge/Temporal`**) :
   - **`TemporalJournalEventStore`** implémente `EventStoreInterface` :
     - `append` : `SignalWithStartWorkflowExecution` avec signal `durableAppend` et politique `WORKFLOW_ID_CONFLICT_POLICY_USE_EXISTING` pour enchaîner sur un workflow déjà démarré.
     - `readStream` : `QueryWorkflow` avec type de requête `readStream` ; réponse = JSON tableau de lignes compatibles `EventSerializer::deserialize`.
   - **Worker** : rejeu de l’historique pour reconstruire le journal à partir des signaux `durableAppend` ; réponses aux queries dans `RespondWorkflowTaskCompletedRequest::query_results`.
   - **Encodage** : payloads `json/plain` (voir `JsonPlainPayload`).

2. **Identifiant de workflow journal** : `durable-journal-{executionId}` avec sanitization des caractères (voir `TemporalJournalSettings::journalWorkflowId`).

3. **Hébergement du poll (spike plan)** :
   - **Symfony Messenger** : transport **receive-only** `temporal-journal://HOST:PORT?namespace=…&task_queue=…` ; chaque `get()` exécute un long poll puis complète la tâche workflow (pas de message applicatif sérialisé).
   - **FrankenPHP / process long** : commande `durable:temporal:journal-worker:run --dsn=…` ; boucle illimitée ou `--max-ticks=N` pour tests.

4. **Dépendances** : `grpc/grpc`, `google/protobuf`, `google/common-protos`, `roadrunner-php/roadrunner-api-dto` — **pas** de `temporal/sdk`.

5. **Phase 2 (hors périmètre immédiat)** — documenté ici pour clore les todos plan :
   - **Worker d’activités** gRPC pour remplacer `ActivityMessage` / Messenger : non implémenté ; v1 hybride = **Messenger** pour `WorkflowRunMessage` et `ActivityMessage` ([ADR009](ADR009-distributed-workflow-dispatch.md)).
   - **`WorkflowResumeDispatcher` Temporal** : non implémenté ; v1 = `MessengerWorkflowResumeDispatcher`.
   - **Refactor `ActivityTransportInterface`** (producteur / consommateur) : reporté tant que les activités restent sur Messenger.

Conséquences
---

- **ext-grpc** requis en runtime pour client + worker.
- **Cohérence append / read** : append par signal est **asynchrone** jusqu’à traitement par le worker ; pas d’Update synchrone dans cette v1 (comportement à documenter côté ops).
- **Limite d’historique Temporal** : journal long → risque de taille d’historique ; **continue-as-new** côté workflow journal = phase 2 (alignement avec [OST001](../ost/OST001-future-opportunities.md)).
- **CI** : job `temporal-bridge` avec **ext-grpc** et test unitaire `JournalStateResolverTest` ; pas encore de test E2E contre un serveur Temporal dans la CI.
- **Psalm** : le répertoire `src/Bridge/Temporal` est **exclu** de `psalm.xml` du monorepo (stubs gRPC / `Override` à traiter ultérieurement) ; **PHPStan** couvre ce code.

Références
---

- [ADR004 — Ports et adapters](ADR004-ports-and-adapters.md)
- [ADR009 — Modèle distribué](ADR009-distributed-workflow-dispatch.md)
- [ADR010 — Parité Temporal / événements](ADR010-temporal-parity-events-and-replay.md)
- [OST001 — Opportunités futures](../ost/OST001-future-opportunities.md)
