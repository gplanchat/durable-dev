# ADR020 — Temporal UI : `WorkflowType` = type métier (Option B)

ADR020-temporal-ui-workflow-type-option-b  
===

Status: **Accepted**

## Context

Le bridge journal `TemporalJournalEventStore` enregistre aujourd’hui un **workflow technique** unique (`TemporalConnection::DEFAULT_WORKFLOW_TYPE = 'DurableJournal'`, voir [ADR014](ADR014-temporal-journal-eventstore-bridge.md)). Temporal UI affiche donc ce nom pour les exécutions du journal, **pas** les types applicatifs déclarés avec `#[Workflow]` et `WorkflowRegistry`.

Les options suivantes avaient été identifiées lors de l’audit « Temporal vs DBAL » :

- **Option A** — UI générique (peu d’intérêt produit).
- **Option B** — **Un workflow Temporal par exécution métier**, avec `WorkflowType.name` = nom du workflow applicatif (`EchoChildWorkflow`, …).
- **Option C** — Temporal comme simple transport sans alignement UI (non retenue).

Contraintes projet **inchangées** : pas de SDK Temporal PHP officiel, pas de RoadRunner comme runtime Durable ; worker gRPC et poll maison ([ADR014](ADR014-temporal-journal-eventstore-bridge.md)).

## Decision

**Retenir l’option B** comme **cible d’architecture** pour l’alignement Temporal UI / modèle applicatif :

- Chaque exécution durable correspond à une **exécution Temporal** dont le **type** visible dans l’UI est le **type métier** issu du `WorkflowRegistry` (chaîne du workflow PHP), et non un type technique fixe pour tout le journal.
- L’implémentation concrète (identifiants `workflow_id`, task queues, signaux, worker gRPC multi-types ou dispatch) est portée par la **roadmap** du bridge et des évolutions de `TemporalConnection` / `TemporalJournalEventStore` — hors périmètre de seul ce ADR.

Le moteur **DBAL** n’a pas de notion équivalente côté serveur : les types métier restent **uniquement** côté PHP et messages Messenger ; l’iso-fonctionnel porte sur l’**API** et le **journal**, pas sur une UI tierce.

## Consequences

- Les évolutions de code du bridge Temporal doivent **viser** ce modèle (documentation produit, revues).
- [ADR014](ADR014-temporal-journal-eventstore-bridge.md) doit être **révisé** quand le comportement runtime reflète Option B (workflow technique `DurableJournal` vs types métier).
- Les tests d’intégration **par moteur** (plan de migration) doivent inclure des scénarios où l’**affichage / contrat** Temporal reflète le type métier une fois implémenté.

## References

- [ADR014](ADR014-temporal-journal-eventstore-bridge.md) — bridge journal Temporal actuel.
- [WA002](../wa/WA002-messenger-transports-and-event-store-engine.md) — orthogonalité Messenger / moteur EventStore.
- Audit plan (Temporal vs DBAL) — §5 et §5.1 schéma cible.
