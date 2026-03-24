# Durable

Bibliothèque PHP et bundle Symfony pour exécutions durables : workflows déterministes, activités asynchrones, journal d’événements et transports (in-memory, DBAL, Messenger). API inspirée du [SDK Temporal PHP](https://docs.temporal.io/develop/php).

## Prérequis

- PHP 8.2+
- Symfony 7.4+ (pour le bundle)

## Installation dans un projet Symfony

```bash
composer require gplanchat/durable
```

Puis enregistrez le bundle et la configuration (voir *Bundle Symfony* plus bas). Pour **essayer tout de suite sans rien configurer**, utilisez le démarrage rapide ci-dessous.

## Démarrage rapide (environ 3 minutes)

Objectif : avoir **un workflow qui s’exécute** et une sortie lisible dans le terminal.

1. **Cloner ce dépôt** et ouvrir l’application exemple :

   ```bash
   cd symfony
   ```

   (dossier `symfony/` à la racine du dépôt `durable`.)

2. **Installer** les dépendances :

   ```bash
   composer install
   ```

3. **Préparer la base** (SQLite par défaut, commande idempotente) :

   ```bash
   php bin/console durable:schema:init
   ```

4. **Exécuter un workflow d’exemple** :

   ```bash
   php bin/console durable:sample GreetingWorkflow --name=Alice
   ```

Vous devez obtenir un message de succès et une salutation du type **Hello, Alice!** dans la sortie.

Ensuite, pour un mode proche de la production (workers qui consomment une file), voir **`symfony/README.md`** (`messenger:consume`, etc.).

> **Vous n’utilisez pas le monorepo ?** Dans votre propre application Symfony : `composer require gplanchat/durable`, copiez une configuration proche de `symfony/config/packages/durable.yaml`, taguez vos classes de workflow avec `durable.workflow`, créez les tables (`durable:schema:init` si vous exposez cette commande ou équivalent), puis déclenchez un workflow depuis votre code ou une commande.

## Concepts

- **Workflow** : orchestration déterministe rejouée depuis un journal d’événements. Les appels asynchrones (activité, timer, enfant, signal, etc.) passent par `WorkflowEnvironment`.
- **Activité** : unité de travail exécutée hors workflow, souvent asynchrone. À concevoir **idempotente** et résiliente (retries).
- **Event store** : journal des événements pour reprise et replay.

## Workflow en classe

Le workflow utilise un **stub** typé vers l’**interface** de l’activité, pas vers l’implémentation :

```php
use App\Durable\Activity\GreetingActivityInterface;
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
        $this->greeting = $environment->activityStub(GreetingActivityInterface::class);
    }

    #[WorkflowMethod]
    public function run(string $name = 'World'): string
    {
        return $this->environment->await($this->greeting->composeGreeting($name));
    }
}
```

(`GreetingActivityInterface` est votre interface métier, définie dans la section suivante.)

## Activités

**Étape 1 — le contrat** : une interface PHP et une méthode marquée avec le nom d’activité utilisé par le runtime.

**Étape 2 — le code métier** : une classe qui implémente cette interface, comme n’importe quel service dans votre application.

```php
namespace App\Durable\Activity;

use Gplanchat\Durable\Attribute\ActivityMethod;

interface GreetingActivityInterface
{
    #[ActivityMethod('composeGreeting')]
    public function composeGreeting(string $name = 'World'): string;
}

final class GreetingActivityHandler implements GreetingActivityInterface
{
    public function composeGreeting(string $name = 'World'): string
    {
        return sprintf('Hello, %s!', $name);
    }
}
```

Le workflow ne référence que **`GreetingActivityInterface`**. L’implémentation **`GreetingActivityHandler`** est invoquée par le **worker** lorsque l’activité est exécutée (souvent via Symfony Messenger dans l’app exemple). Pour un fichier complet qui relie contrats, handlers et transports, ouvrez le dossier **`symfony/src/Durable/`** dans ce dépôt.

## Options retry et gestion d’erreur

Les stubs d’activité se configurent en général dans le constructeur du workflow :

```php
use App\Durable\Activity\GreetingActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;

public function __construct(
    private readonly WorkflowEnvironment $environment,
) {
    $this->greeting = $environment->activityStub(
        GreetingActivityInterface::class,
        ActivityOptions::default()
            ->withMaxAttempts(3)
            ->withInitialInterval(2.0)
            ->withNonRetryableExceptions([ValidationException::class]),
    );
}
```

## Bundle Symfony

Extrait de configuration (exemple complet : **`symfony/config/packages/durable.yaml`** dans ce dépôt) :

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
            - App\Durable\Activity\GreetingActivityInterface
```

Enregistrement des workflows (classes `#[Workflow]`) :

```yaml
# config/services.yaml
services:
    App\Durable\Workflow\:
        resource: '../src/Durable/Workflow/'
        tags: [durable.workflow]
```

**Schéma DBAL** (journal, métadonnées, lien parent-enfant) : les implémentations exposent `createSchema()`. Dans l’app exemple :

```bash
cd symfony && php bin/console durable:schema:init
```

**Workers** (noms de transports alignés sur votre `messenger.yaml`) :

```bash
php bin/console messenger:consume durable_workflows durable_activities -vv
php bin/console durable:activity:consume
```

## Exemple Symfony

Le dossier **`symfony/`** est l’application de référence (workflows, activités, Messenger, SQLite). Après le *démarrage rapide*, vous pouvez lancer d’autres scénarios :

```bash
cd symfony && php bin/console durable:sample ParallelGreetingWorkflow --first=Ada --second=Grace
```

Tests PHPUnit de l’app exemple :

```bash
cd symfony && composer test
```

## Tests (composant)

```bash
composer test
composer cs:check   # contrôle de style
```

## Structure du projet

```
src/                   # Composant (lib)
├── Activity/          # ActivityStub, ActivityOptions, attributs
├── Awaitable/         # Awaitable, Deferred, composites
├── Bundle/            # Intégration Symfony
├── Event/             # Événements du journal
├── Workflow/          # ChildWorkflowStub, loader
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
