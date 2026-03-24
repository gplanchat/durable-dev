# OST004 — Parité fonctionnelle workflow / Temporal (PHP)

## Contexte

Le composant **Durable** s’inspire de l’esprit **Temporal** (voir [OST003](OST003-activity-api-ergonomics.md) pour l’ergonomie des activités). Pour guider le **moteur**, le **journal d’événements** et les **API publiques**, les capacités ci-dessous — documentées pour le **SDK PHP officiel Temporal** — doivent être **prises en charge** ou **cartographiées** explicitement (plein support, sous-ensemble, ou hors périmètre documenté).

Les liens pointent vers la documentation Temporal Platform.

---

## 1. Side effects

**Référence** : [Side Effects — PHP SDK | Temporal](https://docs.temporal.io/develop/php/side-effects)

**Comportement Temporal** : exécuter du code **non déterministe** (UUID, `random_int`, horodatage « maintenant » réel, etc.) **sans** casser le déterminisme du workflow : le résultat est **persisté dans l’historique** d’exécution ; au **replay**, la closure **n’est pas ré-exécutée** — la valeur enregistrée est réutilisée. Une exception dans le side effect fait échouer la tâche workflow. Ne pas muter l’état workflow **dans** le side effect (seulement retourner une valeur).

**Implications Durable** :

- Événement(s) dédié(s) dans le journal (équivalent « résultat de side effect ») + relecture au replay.
- API cible de type **`sideEffect(Closure): Awaitable<T>`** (ou méthode sur `ExecutionContext` / façade workflow), alignée sur **`Workflow::sideEffect()`** côté Temporal.
- Cohérence avec les règles de **déterminisme** et de **replay** ([ADR007](../adr/ADR007-workflow-recovery.md)).

---

## 2. Timers durables

**Référence** : [Durable Timers — PHP SDK | Temporal](https://docs.temporal.io/develop/php/timers)

**Comportement Temporal** : **`Workflow::timer($seconds)`** — sommeil durable ; les timers sont **persistés** ; après indisponibilité worker/service, la reprise continue au bon moment. Contrainte documentée Temporal : ne pas imbriquer un timer dans certains chemins `await` / `awaitWithTimeout` (selon version SDK).

**Implications Durable** :

- **Réalisé** : `TimerScheduled` / `TimerCompleted`, API **`WorkflowEnvironment::timer` / `delay`** ; en mode distribué, **`FireWorkflowTimersMessage`** + handler pour compléter les timers et re-dispatcher la reprise (horloge injectable sur **`ExecutionRuntime`** pour les tests).
- Documenter les **limitations** d’imbrication avec l’`await` interne si elles diffèrent encore du SDK Temporal.

---

## 3. Workflows enfants (child workflows)

**Référence** : [Child Workflows — PHP SDK | Temporal](https://docs.temporal.io/develop/php/child-workflows)

**Comportement Temporal** : planification d’une exécution workflow **depuis** un workflow parent ; événements d’historique dédiés (`StartChildWorkflowExecution*`, etc.) ; **stub** par enfant (`newChildWorkflowStub`, options, `yield` sur la promesse) ; politique **Parent Close Policy** (terminate / abandon / request cancel). Possibilité d’**untyped** stub avec nom de workflow en chaîne.

**Implications Durable** :

- **Réalisé (noyau)** : événements parent `ChildWorkflowScheduled` / `Completed` / `Failed` ; résultat via **`WorkflowEnvironment`** (`executeChildWorkflow`, **`childWorkflowStub`**) ; **`ChildWorkflowOptions`** (`workflowId`, **parent close policy**) ; enfant **inline** ou **async Messenger** ; persistance du lien parent↔enfant (**DBAL**) ; échec enfant projeté sur le journal parent (**kind / class / contexte**) et relu au replay via **`DurableChildWorkflowFailedException`**.
- **Reste / partiel** : timeouts fins à la Temporal, toutes les variantes de policy, ergonomie client « stub » à 100 % du SDK, corrélation run id / même id logique que Temporal.
- L’attribut **`#[Workflow]`** sur les types enfant (voir [OST003](OST003-activity-api-ergonomics.md)) sert de **nom logique** stable ; voir [ADR011](../adr/ADR011-child-workflow-continue-as-new.md).

---

## 4. Continue-as-new

**Référence** : [Continue-As-New — PHP SDK | Temporal](https://docs.temporal.io/develop/php/continue-as-new)

**Comportement Temporal** : clôturer l’exécution courante **avec succès** et en démarrer une **nouvelle** (même **Workflow Id**, nouveau **Run Id**, historique **neuf**), en repassant typiquement un **état** en paramètres. **`Workflow::getInfo()->shouldContinueAsNew`** pour les limites d’historique. **Attention** : avec **Updates** / **Signals**, ne pas appeler continue-as-new **depuis** les handlers — attendre la fin des handlers dans le chemin principal (documentation Temporal).

**Implications Durable** :

- Opération moteur explicite (**`continueAsNew(...)`**) + coupure du journal / chaînage des runs.
- Règles de **sécurité** avec handlers asynchrones (signaux / updates / requêtes) : même principe que Temporal.
- Tests dédiés (éventuellement « test hook » pour forcer le seuil d’historique en CI, comme dans les exemples Temporal).

---

## 5. Signaux, requêtes (queries), mises à jour (updates)

**Référence** : [Workflow message passing — PHP SDK | Temporal](https://docs.temporal.io/develop/php/message-passing)

**Comportement Temporal (résumé)** :

| Mécanisme | Rôle | Contraintes notables |
|-----------|------|----------------------|
| **Signal** | Message asynchrone vers une exécution en cours | `#[SignalMethod]`, retour **`void`** ; peut mettre à jour l’état ; patterns avec **`Workflow::await()`**. |
| **Query** | Lecture **synchrone** de l’état | `#[QueryMethod]`, retour **non void** ; **pas** de logique générant des commandes (pas d’activité / timer dans le handler). |
| **Update** | Mutation **durable** avec réponse | `#[UpdateMethod]` ; validateur optionnel **`#[UpdateValidatorMethod]`** ; peut utiliser activités, timers, enfants ; **`startUpdate`** / **Update-with-Start** côté client ; politiques **unfinished handler** ; **`Workflow::allHandlersFinished()`** avant fin du workflow ; **`Mutex`** / **`Workflow::runLocked`** pour concurrence ; **`#[WorkflowInit]`** pour initialiser avant handlers. |

**Implications Durable** :

- **Attributs PHP** en miroir du modèle Temporal : au minimum **`WorkflowMethod`** (entrée), **`SignalMethod`**, **`QueryMethod`**, **`UpdateMethod`** (+ validateur si besoin) — en cohérence avec **`#[Workflow]`** au niveau classe/interface ([OST003](OST003-activity-api-ergonomics.md)).
- Événements journal : signal reçu, query servie, update acceptée / rejetée / complétée.
- **Plugins PHPStan / Psalm** (prévus dans OST003) : extension aux méthodes de message sur l’interface workflow.
- Composants **dynamiques** (handlers dynamiques) : option avancée, à traiter après le chemin statique.

---

## Synthèse — pistes de priorisation

| Fonctionnalité | Dépend du journal / moteur | Lien OST003 / ADR |
|----------------|----------------------------|-------------------|
| Side effects | Oui (nouveau type d’événement + replay) | ADR007, API `ExecutionContext` |
| Timers durables | Oui (affiner `delay` / timer existant) | PRD001 état actuel |
| Child workflows | Oui (graphe d’exécutions + événements) | `#[Workflow]`, ADR009 |
| Continue-as-new | Oui (chaînage de runs) | ADR007 |
| Signals / Queries / Updates | Oui (handlers + client) | OST003 `#[Workflow]`, futurs attributs méthode |

## Prochaines étapes suggérées

1. ~~**ADR**~~ : **[ADR010](../adr/ADR010-temporal-parity-events-and-replay.md)** — inventaire des **types d’événements** et replay par capacité.
2. ~~**PRD**~~ : **[PRD001](../prd/PRD001-current-component-state.md)** — matrice **Temporal ↔ Durable** et liste d’événements à jour.
3. **Roadmap** : child workflows → continue-as-new → messages (signals / queries / updates) ; side effects et timers couverts par ADR010 et implémentation actuelle.

## Matrice d’état Durable (synthèse)

Document de référence détaillé : **[PRD001](../prd/PRD001-current-component-state.md)**. Table courte pour la roadmap :

| Zone Temporal (PHP SDK) | Support Durable | Commentaire |
|-------------------------|-----------------|-------------|
| Side effects | **Oui** | `SideEffectRecorded`, `WorkflowEnvironment::sideEffect` |
| Durable timers | **Oui** | `FireWorkflowTimersMessage` en distribué |
| Child workflows | **Partiel** | Journal + inline / async + lien DBAL + échec enrichi parent |
| Continue-as-new | **Partiel** | Nouvel `executionId` |
| Signals / Queries / Updates | **Partiel** | Journal + Messenger ; queries = lecture journal |

---

## Références externes

- [Side effects (PHP)](https://docs.temporal.io/develop/php/side-effects)
- [Durable timers (PHP)](https://docs.temporal.io/develop/php/timers)
- [Child workflows (PHP)](https://docs.temporal.io/develop/php/child-workflows)
- [Continue-as-new (PHP)](https://docs.temporal.io/develop/php/continue-as-new)
- [Message passing — Signals, Queries, Updates (PHP)](https://docs.temporal.io/develop/php/message-passing)

## Références internes

- [OST003 — Ergonomie activités / `#[Workflow]` / `#[Activity]`](OST003-activity-api-ergonomics.md)
- [PRD001 — État actuel](../prd/PRD001-current-component-state.md)
- [ADR007 — Workflow recovery](../adr/ADR007-workflow-recovery.md)
- [ADR009 — Distributed workflow dispatch](../adr/ADR009-distributed-workflow-dispatch.md)
- [ADR010 — Événements et replay (parité Temporal)](../adr/ADR010-temporal-parity-events-and-replay.md)
