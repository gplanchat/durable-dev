# Transports Messenger et moteur EventStore (orthogonalité)

WA002-messenger-transports-and-event-store-engine
===

Introduction
---

Cet accord de travail clarifie une **séparation nette** entre :

1. **La façon dont Symfony Messenger achemine les messages** vers `WorkflowRunHandler` et `ActivityRunHandler` (fichiers de transport, DSN, files, consumers).
2. **Le backend de persistance du journal Durable** choisi dans le bundle (`EventStoreInterface` et, le cas échéant, bridge **DBAL** ou **Temporal**).

Ces deux aspects sont **orthogonaux** : on peut combiner un transport Messenger (par exemple **RabbitMQ**) avec un **EventStore DBAL**, sans que l’un impose l’autre.

Contexte
---

Les intégrateurs confondent parfois :

- le transport **Doctrine** de Messenger (`doctrine://…`) utilisé comme **file de messages** ;
- le **`DbalEventStore`**, qui persiste les **événements du journal** dans des tables dédiées via DBAL.

Ce ne sont **pas** la même couche : la première sert à la **file d’attente applicative** ; la seconde au **stockage du journal** au sens Durable.

Décisions
---

### 1. Messagerie (handlers workflow / activité)

- Les messages `WorkflowRunMessage`, `ActivityMessage`, etc. sont **routés** vers des transports nommés (ex. `durable_workflows`, `durable_activities` dans `config/packages/messenger.yaml`).
- La **DSN** de chaque transport peut pointer vers tout **adaptateur Symfony Messenger** supporté (Doctrine, AMQP/RabbitMQ, Redis, in-memory pour les tests, etc.).
- Ce choix régit **uniquement** comment les handlers sont **déclenchés** et avec quelles garanties (retry, DLQ, ordering selon le broker).

### 2. Journal Durable (EventStore)

- Le **moteur** du journal est configuré via le **bundle Durable** (sélection d’implémentation de `EventStoreInterface` : en mémoire, DBAL, bridge Temporal, etc.).
- Ce choix régit **où** et **comment** les événements du workflow sont **lus et écrits** pour la reprise et le rejeu.

### 3. Combinaisons possibles (non exhaustif)

| Messagerie (exemple) | Moteur journal | Remarque |
|------------------------|----------------|----------|
| `doctrine://` pour `durable_workflows` | `DbalEventStore` | Courant ; deux usages DBAL distincts (file vs tables journal). |
| `amqp://` (RabbitMQ) pour `durable_workflows` | `DbalEventStore` | **Valide** : RabbitMQ pour la file, SQL pour le journal. |
| `amqp://` + bridge Temporal pour le journal | `TemporalJournalEventStore` | Valide ; **en plus** un consumer sur la file `durable_temporal_journal` (poll gRPC) est requis pour le bridge Temporal — voir ADR014. |

### 4. Ce qui n’est pas une « double notion de bridge »

- **« Bridge DBAL vs Temporal »** dans la documentation produit / audit désigne le **choix de persistance du journal** (stratégies iso-fonctionnelles côté API PHP), **pas** le type de broker Messenger.
- Changer le transport RabbitMQ ↔ Doctrine pour les workflows **ne remplace pas** la configuration du `EventStoreInterface`.

Références
---

- ADR005 — intégration Messenger (handlers, bundle).
- ADR009 — re-dispatch distribué, `WorkflowRunMessage`.
- ADR014 — bridge Temporal journal (`EventStore`), transport `durable_temporal_journal`.
- Plan d’audit Temporal vs DBAL (`.cursor/plans/audit_temporal_vs_dbal_eed1c9f1.plan.md`), §2bis.

---

*Document rédigé pour guider les intégrateurs Symfony ; à maintenir si les points d’extension Messenger ou les options du bundle évoluent.*
