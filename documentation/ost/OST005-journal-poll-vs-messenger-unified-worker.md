# OST005 — Poll journal Temporal vs consumers Messenger unifiés

OST005-journal-poll-vs-messenger-unified-worker  
===

## Opportunity

Réduire la **surface opérationnelle** : aujourd’hui, le bridge Temporal suppose un **`messenger:consume durable_temporal_journal`** (poll gRPC) **en plus** des consumers `durable_workflows` / `durable_activities` ([ADR014](../adr/ADR014-temporal-journal-eventstore-bridge.md), plan d’audit §2).

## Problem framing

Le **serveur Temporal** exige qu’un worker **prenne** les workflow tasks pour faire progresser l’historique. Sans poll (ou équivalent), les tâches peuvent rester en attente ; la taille d’historique et le comportement serveur doivent être **validés** avant de supprimer un processus.

## Solution directions (à spike)

1. **Fusion** : intégrer la boucle de poll journal dans un **même processus** que les workers Messenger (un binaire qui enchaîne ou multiplexe).
2. **Conserver** deux processus mais **documenter** et **superviser** de façon homogène (même image, même chart Helm).
3. **Abandon partiel** du modèle « journal = workflow Temporal » si un autre mécanisme de persistance satisfait les invariants — **hors scope** court terme (décision ADR distincte).

## Spikes proposés

- Mesurer charge et latence avec / sans poll dédié sur une exécution représentative.
- Vérifier les limites d’historique et le besoin de continue-as-new ([ADR014](../adr/ADR014-temporal-journal-eventstore-bridge.md)).

## Status

**Exploration** — pas de décision figée ; alimente le plan `single-worker-design` et les risques §8 de l’audit.
