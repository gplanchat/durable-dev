# Durable + Symfony sample application

Demonstrates `gplanchat/durable` with **Messenger**, **Temporal** (gRPC workers), and class-based workflows (`App\Durable\Workflow\`). Persistance Durable **sans DBAL** : journal Temporal ou in-memory selon l'environnement.

## Requirements

- PHP 8.2+
- Composer — dependencies live under **`symfony/vendor/`** (Composer default). Run **`composer install`** from this `symfony/` directory.
- Packages **`gplanchat/durable`** and **`gplanchat/durable-bundle`** are resolved via **path** from **`../src/Durable`** and **`../src/DurableBundle`** at the monorepo root. For an app outside the monorepo: `composer require gplanchat/durable-bundle` from Packagist.

After changing the `vendor` layout, clear cache: **`rm -rf var/cache/*`** then **`php bin/console cache:clear`**.

## Installation

```bash
cd symfony
composer install
```

## Architecture

### WorkflowClient

`WorkflowClient` est le point d'entrée pour interagir avec les workflows depuis le code applicatif (contrôleurs, commandes, services) :

```php
// Démarrer un workflow en mode fire-and-forget
$executionId = $client->start('MyWorkflow', ['key' => 'value']);

// Démarrer et attendre la fin (mode synchrone)
$result = $client->startSync('MyWorkflow', ['key' => 'value']);

// Envoyer un signal
$client->signal($executionId, 'approve', ['approved' => true]);

// Interroger l'état d'un workflow
$status = $client->query($executionId, 'getStatus');

// Envoyer un update (signal avec retour)
$result = $client->update($executionId, 'increment', ['amount' => 1]);
```

### ResumeWorkflowMessage

Le `WorkflowClient` (et `WorkflowResumeDispatcher`) dispatche un `ResumeWorkflowMessage` contenant uniquement l'`executionId`. Les métadonnées (type de workflow, payload initial) sont persistées dans `WorkflowMetadataStore` avant le dispatch. Le `ResumeWorkflowHandler` récupère ces métadonnées pour reprendre ou démarrer l'exécution.

### WorkflowTaskRunner (backend Temporal natif)

Pour le backend Temporal natif, `WorkflowTaskRunner` :

1. Reçoit un `PollWorkflowTaskQueueResponse` contenant l'historique Temporal
2. Construit un `TemporalExecutionHistory` en indexant les événements pour des lookups O(1)
3. Lance le handler workflow dans une **`\Fiber`** PHP standard
4. Rejoue l'historique : awaitables déjà résolus → reprise immédiate
5. S'arrête sur le premier awaitable non résolu (nouvelle commande) → retourne les commandes Temporal

Aucun `pcntl_fork()`, aucun Swoole, aucun RoadRunner — **PHP-CLI standard uniquement**.

### Temporal History Cursor

`TemporalHistoryCursor` pagine l'historique Temporal de manière **lazy** via `next_page_token`, sans charger tout l'historique en mémoire d'un coup.

## Dev : Temporal + workers Messenger

Voir **`.env.dev`** : **`DURABLE_DSN`** (`temporal://…`), **`durable.temporal.dsn`** active le bridge Temporal.

Workers typiques (à lancer via `symfony serve` ou manuellement) :

```bash
# Worker journal (poll Temporal pour les workflow tasks)
php bin/console messenger:consume durable_temporal_journal

# Worker activités (poll Temporal pour les activity tasks)
php bin/console messenger:consume durable_temporal_activity

# Worker workflows in-memory (backend Messenger)
php bin/console messenger:consume durable_workflows
```

Voir **`.symfony.local.yaml`** pour la configuration complète des workers avec `symfony serve`.

## PHPUnit (this app)

```bash
cd symfony
composer test
# or: php bin/phpunit
```

Les tests couvrent notamment **`durable:sample`** (workflows d'exemple) et, si configuré, l'intégration Temporal.

### Temporal (intégration réelle, optionnel)

Contre un frontend Temporal joignable depuis la machine qui exécute PHPUnit (souvent `docker compose up -d`). Le port hôte peut différer de `7233` si `TEMPORAL_FRONTEND_PORT` est défini — vérifiez avec `docker compose port temporal 7233`.

- Prérequis : **ext-grpc**.
- Variable : **`DURABLE_DSN`**, par ex. `temporal://127.0.0.1:7233?namespace=default&journal_task_queue=durable-journal&activity_task_queue=durable-activities&tls=0` (adaptez le port).
- Commande : `composer test:temporal-integration` ou `php bin/phpunit --group temporal-integration`.

Sans DSN ou sans serveur joignable, les tests du groupe **`temporal-integration`** sont **ignorés** (la suite `composer test` reste verte).

## Configuration bundle (durable.yaml)

```yaml
durable:
    temporal:
        dsn: '%env(DURABLE_DSN)%'
        # Plus d'option interpreter_mirror_activities — supprimée dans la refactorisation
        # WorkflowTaskRunner gère maintenant le replay natif via Fiber
```

La clé `interpreter_mirror_activities` a été supprimée. Le bridge Temporal utilise désormais `WorkflowTaskRunner` + `TemporalHistoryCursor` pour le replay natif (voir **DUR027**).
