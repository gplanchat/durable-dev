# PRD003 — Base de tests `DurableTestCase`

## Objectif

Centraliser les utilitaires de test pour les exécutions durables (workflows, activités, runtime in-memory et scénarios distribués simulés).

## Comportement livré

- **`DurableTestCase`** fournit :
  - initialisation paresseuse : `stack()`, `eventStore()`, `runtime()`, `activityExecutor()`, `executionId()`
  - assertions sur l’ordre des types d’événements : `assertEventTypesOrder`, `assertEventTypesOrderOn`
  - drainage : `drainActivityQueueOnce`, `runUntilIdle`
  - journal distribué : `assertDistributedWorkflowJournalEquivalent`
  - file d’activités : `assertActivityTransportPendingEquals`

- **Ancien trait** `UsesInMemoryDurableStack` : supprimé ; tout est fusionné dans `DurableTestCase` (voir ADR003).

Les classes de test déclarent en général **`#[CoversClass(…)]`** (et le cas échéant **`#[CoversFunction(…)]`**) pour la couverture, plutôt que `#[CoversNothing]`.

## Références

- `tests/Support/DurableTestCase.php`
- [ADR003 — Standards PHPUnit](../adr/ADR003-phpunit-testing-standards.md)
- [PRD002 — Scénarios pas à pas](PRD002-scenarios-workflow-pas-a-pas.md)
