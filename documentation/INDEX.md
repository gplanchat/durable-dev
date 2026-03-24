# Index de la documentation — Durable

Composant et Bundle Symfony pour exécutions durables (workflows et activités).

---

## ADR — Architecture Decision Records

| ADR | Titre | Description |
|-----|-------|-------------|
| [ADR001](adr/ADR001-adr-management-process.md) | Processus de gestion des ADR | Fondations pour la gestion des décisions d'architecture du projet Durable |
| [ADR002](adr/ADR002-coding-standards.md) | Standards de code | PHP-CS-Fixer, PSR1, PSR12 |
| [ADR003](adr/ADR003-phpunit-testing-standards.md) | Standards PHPUnit | Tests sans mocks excessifs, test doubles dédiés |
| [ADR004](adr/ADR004-ports-and-adapters.md) | Architecture hexagonale | Ports et Adapters (composant vs drivers) |
| [ADR005](adr/ADR005-messenger-integration.md) | Intégration Messenger | Transport des activités via Symfony Messenger |
| [ADR006](adr/ADR006-activity-patterns.md) | Patterns activités | Interface-first, idempotence, gestion des erreurs |
| [ADR007](adr/ADR007-workflow-recovery.md) | Reprise et recovery | Event sourcing, replay, re-dispatch |
| [ADR008](adr/ADR008-error-handling-retries.md) | Erreurs et retries | Classification métier/système, FailureEnvelope |
| [ADR009](adr/ADR009-distributed-workflow-dispatch.md) | Modèle distribué | Re-dispatch workflow, WorkflowRunMessage, WorkflowRegistry |
| [ADR010](adr/ADR010-temporal-parity-events-and-replay.md) | Parité Temporal — événements | Side effects, timers, child, CAN, messages ; replay |
| [ADR011](adr/ADR011-child-workflow-continue-as-new.md) | Child workflows et continue-as-new | childWorkflowStub, écart corrélation run, ParentClosePolicy |

---

## WA — Working Agreements

| WA | Titre | Description |
|----|-------|-------------|
| [WA001](wa/WA001-conventions-and-reviews.md) | Conventions et revues | Nommage, revue de code, gestion des plans Cursor |

---

## OST — Opportunity Solution Trees

| OST | Titre | Description |
|-----|-------|-------------|
| [OST001](ost/OST001-future-opportunities.md) | Opportunités futures | Temporal driver, multi-transport, timers avancés |
| [OST002](ost/OST002-phpunit12-upgrade-checklist.md) | Montée PHPUnit 12 | Checklist avant upgrade, extensions, couverture |
| [OST003](ost/OST003-activity-api-ergonomics.md) | Ergonomie appels d’activités | `#[Activity]` / `#[Workflow]`, `activityStub()`, PSR-6, PHPStan/Psalm |
| [OST004](ost/OST004-workflow-temporal-feature-parity.md) | Parité Temporal (workflows) | Side effects, timers, child, continue-as-new, signals/queries/updates |

---

## PRD — Product Requirements Documents

| PRD | Titre | Description |
|-----|-------|-------------|
| [PRD001](prd/PRD001-current-component-state.md) | État actuel du composant | Workflows, activités, event store, transports |
| [PRD002](prd/PRD002-scenarios-workflow-pas-a-pas.md) | Tests pas à pas (distribué) | File d’activités, reprise, journal intermédiaire |
| [PRD003](prd/PRD003-durable-test-case-base.md) | Base `DurableTestCase` | Stack in-memory, assertions, fin du trait dédié |
| [PRD004](prd/PRD004-ci-github-actions.md) | CI GitHub Actions | CS, PHPUnit strict-coverage, rapport PCOV |

---

## Audit

| Audit | Titre | Description |
|-------|-------|--------------|
| [AUDIT001](audit/AUDIT001-phase2-code-review.md) | Revue code Phase 2 | Cohérence, séparation responsabilités, conformité ADR |

---

## Références

- [LIFECYCLE.md](LIFECYCLE.md) — Cycle de vie des documents
- [Architecture Hive](../architecture/hive/) — ADRs du projet Hive (référence)
- [Architecture Runtime](../architecture/runtime/) — RFCs Runtime
