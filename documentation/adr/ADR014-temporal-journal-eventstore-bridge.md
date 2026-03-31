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
     - `readStream` : `GetWorkflowExecutionHistory` + rejeu des signaux `durableAppend` (même reconstruction que le worker) ; pas de `QueryWorkflow` côté app (évite le blocage tant que le poll journal n’a pas répondu).
   - **Worker** : poll + `RespondWorkflowTaskCompleted` pour **compléter** les tâches workflow Temporal (historique côté serveur) ; indépendant du **readStream** PHP.
   - **Encodage** : payloads `json/plain` (voir `JsonPlainPayload`).

2. **Identifiant de workflow journal** : `durable-journal-{executionId}` avec sanitization des caractères (voir `TemporalConnection::journalWorkflowId`).

3. **Hébergement du poll (spike plan)** :
   - **Symfony Messenger** : DSN unique **`temporal://HOST:PORT?namespace=…&task_queue=…`** (ou `journal_task_queue=…`) ; transport **receive-only** lorsque l’accès est **journal** (pas de `inner` / `purpose=application`). Chaque `get()` exécute un long poll puis complète la tâche workflow (pas de message applicatif sérialisé). Les schémas **`temporal-journal://`** / **`temporal-application://`** sont **obsolètes** et normalisés en **`temporal://`**.
   - **FrankenPHP / process long** : même worker que Symfony Messenger (`messenger:consume <transport>` avec DSN `temporal://…` sans `inner`).

4. **Dépendances** : `grpc/grpc`, `google/protobuf`, `google/common-protos`, `roadrunner-php/roadrunner-api-dto` — **pas** de `temporal/sdk`.

5. **Files applicatives Durable via Temporal (Messenger)** — complément au journal :
   - **`TemporalTransportFactory`** + **`TemporalApplicationTransport`** : même DSN **`temporal://…`** ; l’accès **applicatif** est choisi via **`inner=`** (query ou `options.inner`) ou **`options.purpose=application`**. Enveloppe un transport Symfony Messenger réel (ex. `inner=in-memory://`, `inner=redis://…`). Les DTOs applicatifs (`WorkflowRunMessage`, `ActivityMessage`, signaux, updates, timers) restent les mêmes ; les **`MessageHandler`** bundle ne sont pas dupliqués.
   - **Évolution** : substituer la délégation vers `inner` par envoi / poll gRPC Temporal tout en conservant l’API **`TransportInterface`** et **`messenger:consume`**.
   - **Une connexion, deux usages** : la **connexion** Temporal est **atomique** (`TemporalConnection` / `temporal://`) ; le **choix** journal vs applicatif est un **mode d’accès** (fabrique Messenger + `purpose` / présence de `inner`), pas deux backends produit — voir l’invariant ci-dessous.

**Invariant déploiement Temporal**

- Il **n’existe pas** de cas d’usage cible où le **journal** (`EventStore`) et les **files applicatives** reposeraient sur des **transports / infrastructures distincts** (ex. journal Temporal + files Redis).
- Si Temporal est adopté pour Durable, on assume **un même** déploiement Temporal (cluster, namespace, politique opérationnelle) pour **à la fois** la persistance du journal **et** le chemin des messages applicatifs une fois le bridge gRPC complet ; pas de scénario supporté « moitié Temporal, moitié Messenger classique » côté prod pour ces deux axes.
- Tant que l’accès **applicatif** délègue à **`inner=`**, la transition reste **techniquement** hybride ; l’objectif de conception reste la **convergence** vers Temporal pour les deux, sans maintenir deux stratégies d’infra en parallèle.

6. **Encore hors périmètre (cible)** :
   - **`WorkflowResumeDispatcher`** entièrement backed Temporal sans Messenger : non implémenté ; v1 = `MessengerWorkflowResumeDispatcher` + transports ci-dessus.
   - **Refactor `ActivityTransportInterface`** (producteur / consommateur découplés) : reporté si besoin après stabilisation des transports.

Conséquences
---

- **ext-grpc** requis en runtime pour client + worker.
- **Cohérence append / read** : le journal applicatif lit l’historique via **GetWorkflowExecutionHistory** (pas une query dépendante du worker), ce qui évite de bloquer le démarrage / replay tant que le poll journal n’a pas répondu. Le worker journal sert à **compléter** les tâches workflow côté Temporal, pas à servir le **readStream** PHP.
- **Limite d’historique Temporal** : journal long → risque de taille d’historique ; **continue-as-new** côté workflow journal = phase 2 (alignement avec [OST001](../ost/OST001-future-opportunities.md)).
- **CI** : job `temporal-bridge` avec **ext-grpc** et test unitaire `JournalStateResolverTest` ; pas encore de test E2E contre un serveur Temporal dans la CI.
- **Psalm** : le répertoire `src/Bridge/Temporal` est **exclu** de `psalm.xml` du monorepo (stubs gRPC / `Override` à traiter ultérieurement) ; **PHPStan** couvre ce code.

Références
---

- [ADR004 — Ports et adapters](ADR004-ports-and-adapters.md)
- [ADR009 — Modèle distribué](ADR009-distributed-workflow-dispatch.md)
- [ADR010 — Parité Temporal / événements](ADR010-temporal-parity-events-and-replay.md)
- [OST001 — Opportunités futures](../ost/OST001-future-opportunities.md)
