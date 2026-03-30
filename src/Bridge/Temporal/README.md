# durable-bridge-temporal (`src/Bridge/Temporal`)

Bridge **gRPC** (sans SDK Temporal PHP) pour persister le journal Durable dans un **workflow Temporal** minimal.

Namespace PHP : **`Gplanchat\Bridge\Temporal`**.

**Invariant déploiement** : si vous activez Temporal pour Durable, le **journal** (`EventStore`) et les **files applicatives** partagent la **même** connexion Temporal (`temporal://…`). Le **type d’accès** (worker journal receive-only vs enveloppe applicative) est choisi via **`options.purpose`** (`journal` \| `application`) ou déduit (présence de **`inner`** ⇒ applicatif). Les schémas **`temporal-journal://`** et **`temporal-application://`** restent acceptés et sont normalisés en **`temporal://`**.

## Prérequis

- PHP **ext-grpc**
- Serveur Temporal joignable (`target` type `host:7233`)

## Composants

| Classe | Rôle |
|--------|------|
| `TemporalJournalEventStore` | Implémente `Gplanchat\Durable\Store\EventStoreInterface` |
| `TemporalTransportFactory` | Fabrique unique **`temporal://`** : journal (`TemporalJournalTransport`, receive-only) ou applicatif (`TemporalApplicationTransport` + `inner`) selon `purpose` / `inner` |
| `TemporalJournalTransport` | Transport Symfony Messenger **receive-only** (même DSN `temporal://…`, sans `inner` par défaut) |
| `TemporalApplicationTransport` | Enveloppe un transport Messenger réel (`temporal://…?inner=…` ou `options.inner`) pour les messages applicatifs Durable ; évolution vers gRPC Temporal |
| `RunTemporalJournalWorkerCommand` | Boucle de poll (`durable:temporal:journal-worker:run`) pour FrankenPHP Worker ou systemd |
| `TemporalBridgeBundle` | Enregistre les factories Messenger + la commande journal |

## DSN transport (unique)

```
temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&tls=0
```

Paramètres query : `namespace`, `task_queue` ou `journal_task_queue`, `workflow_type`, `workflow_task_queue`, `activity_task_queue`, `identity`, `tls` (bool).

### Journal (receive-only)

Sans **`inner`** et sans `options.purpose=application` : le transport instancié est **`TemporalJournalTransport`**.

### Files applicatives (`inner`)

```
temporal://127.0.0.1:7233?namespace=default&inner=in-memory://&workflow_task_queue=durable-workflows&activity_task_queue=durable-activities&tls=0
```

Ou bien `temporal://…` **sans** `inner` dans l’URL et **`options: { purpose: application, inner: 'in-memory://' }`** dans la config Messenger.

- **`inner`** (requis pour l’accès applicatif) : DSN du transport Symfony Messenger réel (redis, doctrine, in-memory, etc.).
- **`workflow_task_queue`** / **`activity_task_queue`** : réservés à l’évolution gRPC ; tant que l’enveloppe délègue à **`inner`**, le trafic applicatif transite par ce transport.

## Symfony

1. Dans le monorepo, le code est déjà présent sous `src/Bridge/Temporal` ; pour un dépôt publié à part, `composer require gplanchat/durable-bridge-temporal`.
2. Enregistrer `Gplanchat\Bridge\Temporal\TemporalBridgeBundle` dans le kernel.
3. `framework.messenger.transports.temporal_journal: 'temporal://…'` (ou `purpose: journal` si tu partages le même DSN de base)
4. `messenger:consume temporal_journal` (nom du transport selon ta config).
5. Remplacer `EventStoreInterface` par `TemporalJournalEventStore` (DI explicite).

## FrankenPHP Worker

Même logique que la commande console : exécuter le binaire PHP qui lance `durable:temporal:journal-worker:run --dsn='temporal://…'` (sans `--max-ticks`) sous le mode Worker FrankenPHP, ou équivalent process long.

## Documentation

[Voir ADR014](../../../documentation/adr/ADR014-temporal-journal-eventstore-bridge.md).
