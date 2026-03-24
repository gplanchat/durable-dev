# Durable

Bibliothèque PHP et bundle Symfony pour exécutions durables : workflows déterministes, activités asynchrones, journal d'événements et transports (in-memory, DBAL, Messenger). API inspirée du [SDK Temporal PHP](https://docs.temporal.io/develop/php).

## Prérequis

- PHP 8.2+
- Symfony 7.4+ (pour le bundle)

## Installation

```bash
composer require gplanchat/durable
```

## Concepts

- **Workflow** : orchestration déterministe qui se rejoue depuis un journal d'événements. Toute opération asynchrone (activité, timer, child workflow, signal, etc.) passe par `WorkflowEnvironment`.
- **Activité** : tâche unitaire, éventuellement asynchrone, exécutée hors du workflow. Idempotente et potentiellement retentée.
- **Event store** : journal des événements qui permet replay et reprise.

## Workflow en classe

Déclaration d'un workflow avec les attributs `#[Workflow]` et `#[WorkflowMethod]` :

```php
use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('GreetingWorkflow')]
final class GreetingWorkflow
{
    private readonly ActivityStub $greeting;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->greeting = $environment->activityStub(GreetingActivity::class);
    }

    #[WorkflowMethod]
    public function run(string $name = 'World'): string
    {
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
```

## Activités

Contrat d'activité avec `#[ActivityMethod]` :

```php
use Gplanchat\Durable\Attribute\ActivityMethod;

interface GreetingActivity
{
    #[ActivityMethod('composeGreeting')]
    public function composeGreeting(string $name = 'World'): string;
}
```

Enregistrement côté worker (Symfony, CLI, etc.) :

```php
$activityExecutor->register('composeGreeting', static fn (array $p): string =>
    sprintf('Hello, %s!', $p['name'] ?? 'World')
);
```

## Options retry et gestion d'erreur

Les activity stubs s'initialisent dans le constructeur du workflow, où l'on peut configurer retry et exclusions :

```php
use Gplanchat\Durable\Activity\ActivityOptions;

public function __construct(
    private readonly WorkflowEnvironment $environment,
) {
    $this->greeting = $environment->activityStub(
        GreetingActivity::class,
        ActivityOptions::default()
            ->withMaxAttempts(3)
            ->withInitialInterval(2.0)
            ->withNonRetryableExceptions([ValidationException::class]),
    );
}
```

## Bundle Symfony

Configuration typique (voir l’app `symfony/config/packages/durable.yaml` pour un exemple complet) :

```yaml
# config/packages/durable.yaml
durable:
    distributed: true
    event_store:
        type: dbal              # ou in_memory
        table_name: durable_events
    workflow_metadata:          # requis si distributed + reprises Messenger
        type: dbal              # ou in_memory
        table_name: durable_workflow_metadata
    activity_transport:
        type: messenger         # ou in_memory, dbal
        transport_name: durable_activities
    child_workflow:
        async_messenger: true   # enfant = message WorkflowRunMessage séparé (avec distributed)
        parent_link_store:
            type: dbal           # ou in_memory — persistance du lien parent↔enfant multi-workers
            table_name: durable_child_workflow_parent_link
    activity_contracts:
        cache: cache.app
        contracts:
            - App\Durable\Activity\GreetingActivity
```

Enregistrement des workflows (classes `#[Workflow]`) :

```yaml
# config/services.yaml
services:
    App\Durable\Workflow\:
        resource: '../src/Durable/Workflow/'
        tags: [durable.workflow]
```

**Schéma DBAL** (journal, métadonnées, lien parent-enfant) : les implémentations exposent `createSchema()`. L’app exemple fournit :

```bash
cd symfony && php bin/console durable:schema:init
```

Lancement des workers (noms de transports alignés sur votre `messenger.yaml`) :

```bash
php bin/console messenger:consume durable_workflows durable_activities -vv
php bin/console durable:activity:consume
```

## Exemple Symfony

Le dossier `symfony/` contient une application exemple (workflows en classe, `activity_contracts`, Messenger + SQLite). Voir **`symfony/README.md`**. **Première fois** : `php bin/console durable:schema:init`, puis par exemple :

```bash
cd symfony && php bin/console durable:sample GreetingWorkflow --name=Alice
```

Tests PHPUnit de l’app exemple (commande `durable:schema:init`, SQLite mémoire) :

```bash
cd symfony && composer test
```

## Tests

```bash
composer test
composer cs:check   # contrôle style
```

## Structure du projet

```
src/                   # Composant (lib)
├── Activity/          # ActivityStub, ActivityOptions, attributs
├── Awaitable/         # Awaitable, Deferred, composites
├── Bundle/             # Intégration Symfony
├── Event/              # Événements du journal
├── Workflow/           # ChildWorkflowStub, loader
├── ExecutionContext.php
├── ExecutionEngine.php
├── WorkflowEnvironment.php
└── ...

symfony/               # Application exemple
documentation/         # ADR, OST, PRD
```

## Documentation

- [Index de la documentation](documentation/INDEX.md) — ADR, conventions, OST
- [CHANGELOG.md](CHANGELOG.md) — ruptures API et évolutions

## Licence

Propriétaire.
