# durable-bridge-temporal (`src/Bridge/Temporal`)

Bridge **gRPC** (sans SDK Temporal PHP) pour persister le journal Durable dans un **workflow Temporal** minimal.

Namespace PHP : **`Gplanchat\Bridge\Temporal`**.

## Prérequis

- PHP **ext-grpc**
- Serveur Temporal joignable (`target` type `host:7233`)

## Composants

| Classe | Rôle |
|--------|------|
| `TemporalJournalEventStore` | Implémente `Gplanchat\Durable\Store\EventStoreInterface` |
| `TemporalJournalTransport` + `TemporalJournalTransportFactory` | Transport Symfony Messenger **receive-only** (`temporal-journal://…`) |
| `RunTemporalJournalWorkerCommand` | Boucle de poll (`durable:temporal:journal-worker:run`) pour FrankenPHP Worker ou systemd |
| `TemporalBridgeBundle` | Enregistre la factory Messenger + la commande |

## DSN transport

```
temporal-journal://127.0.0.1:7233?namespace=default&task_queue=durable-journal&tls=0
```

Paramètres query : `namespace`, `task_queue`, `workflow_type`, `identity`, `tls` (bool).

## Symfony

1. Dans le monorepo, le code est déjà présent sous `src/Bridge/Temporal` ; pour un dépôt publié à part, `composer require gplanchat/durable-bridge-temporal`.
2. Enregistrer `Gplanchat\Bridge\Temporal\TemporalBridgeBundle` dans le kernel.
3. `framework.messenger.transports.temporal_journal: 'temporal-journal://…'`
4. `messenger:consume temporal_journal` (nom du transport selon ta config).
5. Remplacer `EventStoreInterface` par `TemporalJournalEventStore` (DI explicite).

## FrankenPHP Worker

Même logique que la commande console : exécuter le binaire PHP qui lance `durable:temporal:journal-worker:run --dsn='temporal-journal://…'` (sans `--max-ticks`) sous le mode Worker FrankenPHP, ou équivalent process long.

## Documentation

[Voir ADR014](../../../documentation/adr/ADR014-temporal-journal-eventstore-bridge.md).
