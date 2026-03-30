---
title: ADR
weight: 46
---

*Architecture Decision Records* — décisions techniques tracées avec contexte et conséquences. L’index et les intitulés ci-dessous proviennent de `documentation/INDEX.md` (synchronisé par script). Meta : {{< ghdoc "adr/ADR001-adr-management-process.md" "ADR001 — processus" >}}.

| ADR | Titre (INDEX) | Description |
|-----|------------|-------------|
| {{< ghdoc "adr/ADR001-adr-management-process.md" "ADR001" >}} | ADR management process | Foundations for managing architecture decisions in the Durable project |
| {{< ghdoc "adr/ADR002-coding-standards.md" "ADR002" >}} | Coding standards | PHP-CS-Fixer, PSR-1, PSR-12 |
| {{< ghdoc "adr/ADR003-phpunit-testing-standards.md" "ADR003" >}} | PHPUnit standards | Tests without excessive mocks, dedicated test doubles |
| {{< ghdoc "adr/ADR004-ports-and-adapters.md" "ADR004" >}} | Hexagonal architecture | Ports and adapters (component vs drivers) |
| {{< ghdoc "adr/ADR005-messenger-integration.md" "ADR005" >}} | Messenger integration | Activity transport via Symfony Messenger |
| {{< ghdoc "adr/ADR006-activity-patterns.md" "ADR006" >}} | Activity patterns | Interface-first, idempotence, error handling |
| {{< ghdoc "adr/ADR007-workflow-recovery.md" "ADR007" >}} | Recovery and replay | Event sourcing, replay, re-dispatch |
| {{< ghdoc "adr/ADR008-error-handling-retries.md" "ADR008" >}} | Errors and retries | Business vs system classification, FailureEnvelope |
| {{< ghdoc "adr/ADR009-distributed-workflow-dispatch.md" "ADR009" >}} | Distributed model | Workflow re-dispatch, WorkflowRunMessage, WorkflowRegistry |
| {{< ghdoc "adr/ADR010-temporal-parity-events-and-replay.md" "ADR010" >}} | Temporal parity — events | Side effects, timers, child, CAN, messages; replay |
| {{< ghdoc "adr/ADR011-child-workflow-continue-as-new.md" "ADR011" >}} | Child workflows and continue-as-new | childWorkflowStub, run correlation gap, ParentClosePolicy |
| {{< ghdoc "adr/ADR012-activity-stub-metadata-and-static-analysis.md" "ADR012" >}} | Activity stub, PSR-6 cache, warmup, static analysis | activityStub, ActivityContractResolver, PHPStan extension |
| {{< ghdoc "adr/ADR013-activity-contract-cache-production-policy.md" "ADR013" >}} | Cache PSR-6 des contrats d’activité en production | Miss, absence de pool, recommandations charge |
| {{< ghdoc "adr/ADR014-temporal-journal-eventstore-bridge.md" "ADR014" >}} | Temporal journal EventStore (gRPC sans SDK) | `TemporalJournalEventStore`, transport Messenger, worker console |
| {{< ghdoc "adr/ADR015-magento-durable-module.md" "ADR015" >}} | Module Magento Durable | `src/DurableModule/`, backends DBAL / Temporal, sans Messenger ni RoadRunner |
| {{< ghdoc "adr/ADR016-dedicated-dbal-connection-and-unbuffered-reads.md" "ADR016" >}} | Connexion DBAL dédiée et lectures unbuffered | `durable.dbal_connection`, alias `durable.dbal.connection`, options PDO MySQL |
| {{< ghdoc "adr/ADR017-splitsh-ci-and-satellite-pushes.md" "ADR017" >}} | Splitsh CI et push vers dépôts satellites | Vérif vs push PAT, cache binaire, `SPLITSH_PUSH_TOKEN` |

[← Retour à l’architecture]({{< relref "/docs/architecture/" >}})
