# Documentation index — Durable

Symfony component and bundle for durable execution (workflows and activities).

---

## ADR — Architecture Decision Records

| ADR | Title | Description |
|-----|-------|-------------|
| [ADR001](adr/ADR001-adr-management-process.md) | ADR management process | Foundations for managing architecture decisions in the Durable project |
| [ADR002](adr/ADR002-coding-standards.md) | Coding standards | PHP-CS-Fixer, PSR-1, PSR-12 |
| [ADR003](adr/ADR003-phpunit-testing-standards.md) | PHPUnit standards | Tests without excessive mocks, dedicated test doubles |
| [ADR004](adr/ADR004-ports-and-adapters.md) | Hexagonal architecture | Ports and adapters (component vs drivers) |
| [ADR005](adr/ADR005-messenger-integration.md) | Messenger integration | Activity transport via Symfony Messenger |
| [ADR006](adr/ADR006-activity-patterns.md) | Activity patterns | Interface-first, idempotence, error handling |
| [ADR007](adr/ADR007-workflow-recovery.md) | Recovery and replay | Event sourcing, replay, re-dispatch |
| [ADR008](adr/ADR008-error-handling-retries.md) | Errors and retries | Business vs system classification, FailureEnvelope |
| [ADR009](adr/ADR009-distributed-workflow-dispatch.md) | Distributed model | Workflow re-dispatch, WorkflowRunMessage, WorkflowRegistry |
| [ADR010](adr/ADR010-temporal-parity-events-and-replay.md) | Temporal parity — events | Side effects, timers, child, CAN, messages; replay |
| [ADR011](adr/ADR011-child-workflow-continue-as-new.md) | Child workflows and continue-as-new | childWorkflowStub, run correlation gap, ParentClosePolicy |
| [ADR012](adr/ADR012-activity-stub-metadata-and-static-analysis.md) | Activity stub, PSR-6 cache, warmup, static analysis | activityStub, ActivityContractResolver, PHPStan extension |
| [ADR013](adr/ADR013-activity-contract-cache-production-policy.md) | Cache PSR-6 des contrats d’activité en production | Miss, absence de pool, recommandations charge |
| [ADR014](adr/ADR014-temporal-journal-eventstore-bridge.md) | Temporal journal EventStore (gRPC sans SDK) | `TemporalJournalEventStore`, transport Messenger, `messenger:consume` journal |
| [ADR015](adr/ADR015-magento-durable-module.md) | Module Magento Durable | `src/DurableModule/`, backends DBAL / Temporal, sans Messenger ni RoadRunner |
| [ADR016](adr/ADR016-dedicated-dbal-connection-and-unbuffered-reads.md) | Connexion DBAL dédiée et lectures unbuffered | `durable.dbal_connection`, alias `durable.dbal.connection`, options PDO MySQL |
| [ADR017](adr/ADR017-splitsh-ci-and-satellite-pushes.md) | Splitsh CI et push vers dépôts satellites | Vérif vs push PAT, cache binaire, `SPLITSH_PUSH_TOKEN` |
| [ADR018](adr/ADR018-no-silent-catch-blocks.md) | Pas de `catch` muets | Interdiction explicite, journalisation / rethrow / contrat métier, revue |
| [ADR019](adr/ADR019-event-store-cursor-pagination.md) | Pagination curseur EventStore | Keyset DBAL (`id`), `next_page_token` Temporal ; mémoire client vs ADR016 |

---

## WA — Working Agreements

| WA | Title | Description |
|----|-------|-------------|
| [WA001](wa/WA001-conventions-and-reviews.md) | Conventions and reviews | Naming, code review, Cursor plan management |

---

## OST — Opportunity Solution Trees

| OST | Title | Description |
|-----|-------|-------------|
| [OST001](ost/OST001-future-opportunities.md) | Future opportunities | Temporal driver, multi-transport, advanced timers |
| [OST002](ost/OST002-phpunit12-upgrade-checklist.md) | PHPUnit 12 upgrade | Pre-upgrade checklist, extensions, coverage |
| [OST003](ost/OST003-activity-api-ergonomics.md) | Activity call ergonomics | `#[Activity]` / `#[Workflow]`, `activityStub()`, PSR-6, PHPStan/Psalm |
| [OST004](ost/OST004-workflow-temporal-feature-parity.md) | Temporal parity (workflows) | Side effects, timers, child, continue-as-new, signals/queries/updates |

---

## PRD — Product Requirements Documents

| PRD | Title | Description |
|-----|-------|-------------|
| [PRD001](prd/PRD001-current-component-state.md) | Current component state | Workflows, activities, event store, transports |
| [PRD002](prd/PRD002-in-flight-workflow-scenarios.md) | In-flight workflow scenarios (distributed) | Activity queue, resume, intermediate log |
| [PRD003](prd/PRD003-durable-test-case-base.md) | `DurableTestCase` base | In-memory stack, assertions, dedicated worker teardown |
| [PRD004](prd/PRD004-ci-github-actions.md) | GitHub Actions CI | CS, PHPUnit strict coverage, PCOV report |
| [PRD005](prd/PRD005-symfony-empty-project-recipe.md) | Empty Symfony project recipe (~3 min) | Monorepo quick start, bundle integration, auto-registered handlers |

---

## Plans (implémentation)

| Plan | Title | Description |
|------|-------|-------------|
| [PLAN001](plans/PLAN001-lib-decouple-messenger.md) | Découpler Messenger de la lib | **Fait** : `MessengerActivityTransport` dans le bundle ; `symfony/messenger` retiré de `gplanchat/durable` — débloque intégrations sans Messenger (ex. Magento) |

---

## Audit

| Audit | Title | Description |
|-------|-------|-------------|
| [AUDIT001](audit/AUDIT001-phase2-code-review.md) | Phase 2 code review | Consistency, separation of concerns, ADR compliance |

---

## References

- [LIFECYCLE.md](LIFECYCLE.md) — Document lifecycle
- [Hive architecture](../architecture/hive/) — Hive project ADRs (reference)
- [Runtime architecture](../architecture/runtime/) — Runtime RFCs
