# PRD002 — Scénarios workflow « en cours d'exécution » (tests distribués)

## Contexte

Les tests fonctionnels `WorkflowLaunchWithActivitiesTest` valident le journal **final** après un `InMemoryWorkflowRunner::run()` complet. Pour le runtime distribué simulé, il manquait une couverture explicite des **états intermédiaires** : file d'attente des activités, reprise après `ActivityCompleted`, terminaison après la dernière activité.

## Comportement attendu

1. **Après suspension** sur le premier `await` d’activité : la file contient les messages attendus (nom + payload métier) ; le journal contient `ExecutionStarted` et les `ActivityScheduled` correspondants, sans `ActivityCompleted` pour ces attentes.
2. **Après `drain` d’une activité** : la file se vide (ou se met à jour) ; le journal inclut le `ActivityCompleted` attendu.
3. **Après `resume`** : le workflow rejoue depuis l’historique, enfile la prochaine activité si le code métier continue ; on peut répéter jusqu’à l’absence de suspension.
4. **Dernière reprise** : plus de message en file pour cette étape ; `ExecutionCompleted` avec le résultat final ; journal aligné sur le scénario de référence « bout en bout ».

## Implémentation (résumé)

| Élément | Rôle |
|--------|------|
| `InMemoryActivityTransport::inspectPendingActivities()` / `pendingCount()` | Snapshot FIFO non destructif pour assertions |
| `StepwiseWorkflowHarness` | `start` / `resume` / `drainOneQueuedActivity` autour de `ExecutionEngine` + `ExecutionRuntime` (mode `distributed`) |
| `DistributedWorkflowExpectedJournal::*After*` | Journaux attendus par palier |
| `DurableTestCase::assertActivityTransportPendingEquals()` | Assertion PHPUnit sur la file |
| `WorkflowStepwiseDistributedExecutionTest` | Scénarios greet + trois doubles en chaîne |

## Critères d’acceptation

- [x] Au moins un scénario à une activité avec vérifications file + journal + résultat final.
- [x] Au moins un scénario à plusieurs activités séquentielles avec vérifications à chaque suspend / drain / resume.
- [x] Scénarios **`all()`** : trois activités planifiées avant le premier suspend (file à 3), puis drains FIFO jusqu’au résultat agrégé.
- [x] Scénarios **`any()`** : deux activités en file ; un drain suffit pour que la reprise termine le workflow ; la deuxième peut rester en file (comportement drain unitaire vs `runUntilIdle`).
- [x] Le journal final reste équivalent aux scénarios existants (`DistributedWorkflowJournalEquivalentConstraint`).

## Références

- `tests/functional/Bridge/WorkflowStepwiseDistributedExecutionTest.php`
- `tests/Support/StepwiseWorkflowHarness.php`
- ADR009 — modèle distribué
