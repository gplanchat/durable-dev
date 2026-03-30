---
title: Temporal avec Durable
weight: 30
---

**Temporal** peut intervenir dans la stack Durable de deux manières conceptuellement distinctes :

1. **Inspiration de l’API** — Les workflows / activités / replay s’inspirent du modèle [Temporal PHP](https://docs.temporal.io/develop/php), sans imposer un serveur Temporal.  
2. **Bridge gRPC « journal Temporal »** — Le paquet **`gplanchat/durable-bridge-temporal`** (code sous `src/Bridge/Temporal`, namespace `Gplanchat\Bridge\Temporal`) implémente **`EventStoreInterface`** en s’appuyant sur un **workflow Temporal minimal** via **gRPC** et les stubs protobuf (par ex. `roadrunner-php/roadrunner-api-dto`). **Aucun SDK Temporal PHP** n’est requis.

Cette page porte sur le **point 2** : utiliser Temporal comme **couche de persistance du journal** d’événements Durable, et à terme le **même** Temporal pour les **messages applicatifs** — il n’y a **pas** de scénario cible où le journal serait sur Temporal et les files applicatives sur un autre backend (Redis, etc.) de façon durable.

## Prérequis runtime

- Extension PHP **grpc**  
- Un serveur **Temporal** joignable (`hôte:7233` typiquement)

## Idée générale

- **`TemporalJournalEventStore`** : append et lecture de flux d’événements compatibles avec la sérialisation Durable existante.  
- Les détails du protocole (signaux, requêtes, worker qui rejoue les signaux pour reconstruire le journal) sont décrits dans **ADR014** du dépôt.  
- **Pas de RoadRunner** imposé par ce bridge ; pas de dépendance Composer à `temporal/sdk`.

## Composants utiles (aperçu)

| Élément | Rôle |
|--------|------|
| `TemporalJournalEventStore` | Implémente `Gplanchat\Durable\Store\EventStoreInterface` |
| `TemporalTransportFactory` | DSN unique **`temporal://`** ; journal (`TemporalJournalTransport`) ou applicatif (`TemporalApplicationTransport` + `inner`) selon `purpose` / présence de `inner` |
| `TemporalJournalTransport` | Transport Symfony Messenger **receive-only** (même DSN sans `inner` par défaut) |
| `TemporalApplicationTransport` | Enveloppe un transport Messenger réel (`temporal://…?inner=…`) pour les messages applicatifs |
| `RunTemporalJournalWorkerCommand` | Commande `durable:temporal:journal-worker:run` (boucle de poll pour worker long) |
| `TemporalBridgeBundle` | Enregistre les fabriques Messenger et la commande journal |

## DSN du transport Messenger

Exemple :

```text
temporal://127.0.0.1:7233?namespace=default&task_queue=durable-journal&tls=0
```

Paramètres typiques : `namespace`, `task_queue` ou `journal_task_queue`, `workflow_type`, `identity`, `tls` (booléen). Les schémas **`temporal-journal://`** et **`temporal-application://`** restent acceptés et sont normalisés en **`temporal://`**.

Dans un **docker-compose** du dépôt, depuis un service PHP sur le même réseau, l’hôte est souvent `temporal` et le port **7233** ; depuis la machine hôte, utilisez `127.0.0.1:7233`.

## Intégration Symfony (schéma)

1. `composer require gplanchat/durable-bridge-temporal` (hors monorepo).  
2. Enregistrer **`Gplanchat\Bridge\Temporal\TemporalBridgeBundle`** dans le kernel.  
3. Déclarer un transport Messenger, par exemple :  
   `framework.messenger.transports.temporal_journal: 'temporal://…'`  
4. Lancer le consommateur : `messenger:consume temporal_journal` (nom à adapter).  
5. **Injection** : fournir `TemporalJournalEventStore` (ou votre binding) comme implémentation de **`EventStoreInterface`** à la place du store DBAL.

## Worker « FrankenPHP » ou process long

La même logique que la commande console : exécuter un binaire PHP qui lance  
`durable:temporal:journal-worker:run --dsn='temporal://…'`  
sans limite de ticks en production, ou `--max-ticks` pour les tests.

## Activités et reprise de workflow « classiques » Durable

En **v1** du bridge, le focus **gRPC** immédiat est le **journal** (EventStore). Les **messages applicatifs** (`WorkflowRunMessage`, `ActivityMessage`, signaux, updates, timers) utilisent le **même** DSN **`temporal://…?inner=…`** (ou `options.purpose` / `options.inner`) : la file réelle reste le transport Messenger indiqué par **`inner`** (voir ADR014) ; le branchement gRPC Temporal complet est une **évolution**.

Le **dispatch de reprise** de workflow distribué reste typiquement **`MessengerWorkflowResumeDispatcher`** ; un dispatcher Temporal dédié est hors périmètre immédiat.

## Limites opérationnelles à anticiper

- **Append asynchrone** : l’append via signal est asynchrone jusqu’à traitement par le worker du journal — pas de garantie synchrone stricte « écrit puis lu » au sens transactionnel local.  
- **Taille d’historique Temporal** : un journal très long peut approcher les limites d’historique Temporal ; des stratégies type **continue-as-new** côté workflow journal relèvent de pistes futures (voir OST001 / ADR014).

## Pour aller plus loin

- README technique : `src/Bridge/Temporal/README.md` dans le dépôt  
- Décision d’architecture : **ADR014** — *Temporal journal EventStore bridge* (`documentation/adr/ADR014-temporal-journal-eventstore-bridge.md`)

Pour le modèle général workflows / activités / replay, voir aussi [Workflows et activités]({{< relref "workflows-et-activites" >}}) et [Installation du bundle Symfony]({{< relref "installation-bundle" >}}).
