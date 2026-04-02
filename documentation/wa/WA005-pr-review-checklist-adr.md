# Checklist de revue PR (conformité ADR)

WA005-pr-review-checklist-adr
===

Introduction
---

Ce **Working Agreement** fournit une **checklist** pour les revues de pull request sur le dépôt Durable, alignée sur les [ADR](../adr/) numérotés. Elle complète [WA001 — Conventions et revues](WA001-conventions-and-reviews.md) : critères de **merge** (approbation, tests) inchangés ; ici on **structure** les points à vérifier selon les décisions d’architecture.

**Usage** : pour chaque PR, **parcourir les sections** pertinentes au diff (pas tout cocher si hors périmètre). Les items **N/A** peuvent être ignorés avec une brève note en commentaire de revue.

---

### Processus et documentation

| Ref | À vérifier |
|-----|------------|
| [ADR001](../adr/ADR001-adr-management-process.md) | Nouvelle décision d’architecture documentée si le changement en crée une ; [INDEX.md](../INDEX.md) à jour si nouveau doc ; `documentation/adr/ADR{nnn}-*.md` respecte titre + `===` + sections requises. |
| [ADR002](../adr/ADR002-coding-standards.md) | `declare(strict_types=1)` sur les nouveaux fichiers PHP ; `.php-cs-fixer.dist.php` respecté (`./vendor/bin/php-cs-fixer fix` OK) ; typage explicite des paramètres / retours quand c’est attendu. |
| [ADR003](../adr/ADR003-phpunit-testing-standards.md) | Pas de `createMock()` / mocks PHPUnit pour du domaine ; tests doubles dédiés ou sujets réels ; pas de `@depends` pour imposer l’ordre. |

### Structure et intégrations

| Ref | À vérifier |
|-----|------------|
| [ADR004](../adr/ADR004-ports-and-adapters.md) | Dépendances : `gplanchat/durable` sans `symfony/*` ; IO / framework dans `DurableBundle` / `Bridge`. |
| [ADR005](../adr/ADR005-messenger-integration.md) | Activités via Messenger : messages, transport, handlers cohérents avec le modèle documenté. |
| [ADR006](../adr/ADR006-activity-patterns.md) | Contrats d’activité clairs ; idempotence / erreurs métier vs système ; pas de logique métier lourde dans le transport. |
| [ADR007](../adr/ADR007-workflow-recovery.md) | Recovery / replay : journal, reprise, pas de rupture du modèle d’événements append-only. |
| [ADR008](../adr/ADR008-error-handling-retries.md) | Classification erreurs ; `FailureEnvelope` / retries alignés ; pas de masquage incohérent avec le journal durable. |
| [ADR009](../adr/ADR009-distributed-workflow-dispatch.md) | Distributed : `WorkflowRunMessage`, registre, re-dispatch sans casser les contrats d’exécution. |

### Parité Temporal et workflows

| Ref | À vérifier |
|-----|------------|
| [ADR010](../adr/ADR010-temporal-parity-events-and-replay.md) | Comportements événement / replay (timers, side effects, enfants, etc.) cohérents avec l’ADR ; `null` d’activité géré avec `array_key_exists` si pertinent. |
| [ADR011](../adr/ADR011-child-workflow-continue-as-new.md) | Enfants / continue-as-new : options, corrélation, pas de régression sur les gaps documentés. |
| [ADR012](../adr/ADR012-activity-stub-metadata-and-static-analysis.md) | `activityStub`, cache PSR-6, extension PHPStan : pas de contournement qui casse l’analyse statique attendue. |
| [ADR013](../adr/ADR013-activity-contract-cache-production-policy.md) | Politique de cache des contrats d’activité en prod respectée (TTL, warmup, charge). |
| [ADR014](../adr/ADR014-temporal-journal-eventstore-bridge.md) | Bridge Temporal : gRPC, journal, `TemporalJournalEventStore` / transport sans dépendre du SDK workflow PHP. |
| [ADR016](../adr/ADR016-dedicated-dbal-connection-and-unbuffered-reads.md) | DBAL durable : connexion dédiée, `unbuffered` / pagination alignés avec l’ADR. |
| [ADR017](../adr/ADR017-splitsh-ci-and-satellite-pushes.md) | Si CI / splitsh / satellites : secrets, tokens, jobs conformes. |
| [ADR018](../adr/ADR018-no-silent-catch-blocks.md) | Aucun `catch` muet : log PSR-3 + contexte, rethrow, ou résultat métier explicite ; cas documenté si « ignore » volontaire. |
| [ADR019](../adr/ADR019-event-store-cursor-pagination.md) | Pagination curseur EventStore / Temporal : `next_page_token`, clés stables, pas de `OFFSET` pour le gros volume. |
| [ADR020](../adr/ADR020-temporal-ui-workflow-type-option-b.md) | Temporal UI : nom d’exécution / type métier aligné avec `WorkflowRegistry` (Option B). |

---

### Synthèse rapide (toute PR)

- [ ] **Tests** : exécution PHPUnit pertinente ; pas de mocks PHPUnit abusifs sur le domaine ([ADR003](../adr/ADR003-phpunit-testing-standards.md)).
- [ ] **Style** : PHP-CS-Fixer / strict types ([ADR002](../adr/ADR002-coding-standards.md)).
- [ ] **Erreurs** : pas de `catch` silencieux ([ADR018](../adr/ADR018-no-silent-catch-blocks.md)).
- [ ] **Architecture** : hexagonalité, emplacement des changements ([ADR004](../adr/ADR004-ports-and-adapters.md)).
- [ ] **Docs** : INDEX si nouveau document ; ADR si nouvelle décision ([ADR001](../adr/ADR001-adr-management-process.md)).

---

Références
---

- [WA001 — Conventions et revues](WA001-conventions-and-reviews.md)
- [INDEX.md](../INDEX.md) — liste des ADR
