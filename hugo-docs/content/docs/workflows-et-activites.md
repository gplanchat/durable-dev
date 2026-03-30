---
title: Workflows et activités
weight: 10
---

> Si vous découvrez Durable, commencez plutôt par le **[parcours guidé — installation et premier workflow]({{< relref "/docs/parcours-premier-workflow/" >}})** : vous verrez les commandes et un exemple **avant** d’entrer dans la théorie ci-dessous.

## Rôles : orchestration vs effets de bord

- **Workflow** : code d’**orchestration** rejoué à partir d’un **journal d’événements**. Il décrit *quoi* enchaîner (activités, temporisations, workflows enfants, etc.). Il ne doit pas contenir d’effets de bord non déterministes « bruts » (voir ci‑dessous).
- **Activité** : unité de travail exécutée **hors** du rejeu direct du workflow — en pratique par un **worker** (Messenger, transport DBAL, etc.). Elle peut appeler la base, des API HTTP, envoyer des e‑mails. Les activités peuvent être **réessayées** : viser l’**idempotence** lorsque c’est possible.

- **Journal (event store)** : historique des événements du run. C’est la **source de vérité** pour reprendre après panne et pour le **replay** sans dupliquer le travail déjà enregistré.

## Créer un workflow (classe + attributs)

1. Classe annotée avec `#[Workflow('MonTypeWorkflow')]` (nom logique utilisé par le moteur).
2. Une méthode d’entrée `#[WorkflowMethod]` (souvent `run(...)`).
3. Injection de **`WorkflowEnvironment`** : c’est le point d’accès aux stubs d’activités, timers, effets de bord contrôlés, workflows enfants, etc.

Exemple minimal (extrait conceptuel) :

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

Le workflow ne référence **que l’interface** d’activité ; l’implémentation concrète est choisie à l’exécution par l’infrastructure.

## Créer des activités : contrat puis implémentation

**Étape 1 — contrat** : une **interface** PHP avec des méthodes annotées `#[ActivityMethod('nomLogique')]`. Le nom doit correspondre à ce que le moteur utilise pour corréler appel et exécution.

**Étape 2 — implémentation** : une classe qui implémente l’interface. Sous Symfony, `#[AsDurableActivity(contract: MonInterface::class)]` permet au bundle d’**enregistrer** le handler sans branchement manuel dans un bootstrap.

```php
use Gplanchat\Durable\Attribute\ActivityMethod;
use Gplanchat\Durable\Bundle\Attribute\AsDurableActivity;

interface GreetingActivityInterface
{
    #[ActivityMethod('composeGreeting')]
    public function composeGreeting(string $name = 'World'): string;
}

#[AsDurableActivity(contract: GreetingActivityInterface::class)]
final class GreetingActivityHandler implements GreetingActivityInterface
{
    public function composeGreeting(string $name = 'World'): string
    {
        return sprintf('Hello, %s!', $name);
    }
}
```

Listez aussi les interfaces sous **`durable.activity_contracts.contracts`** dans la configuration YAML pour le **cache** des métadonnées de contrats (warmup / analyse statique).

## Comment répartir le code (contraintes)

| Zone | Responsabilité | Contraintes |
|------|----------------|-------------|
| **Interface d’activité** | Contrat stable, types d’entrée/sortie | Éviter d’y mettre de la logique ; méthodes alignées avec `ActivityMethod` |
| **Handler d’activité** | I/O, règles métier avec effets de bord | **Idempotence** si la même entrée peut être rejouée ; erreurs métier vs transitoires |
| **Workflow** | Enchaînement, branchements, attentes | **Déterminisme** : pas d’aléa / horodatage / I/O directs hors mécanismes prévus |
| **WorkflowEnvironment** | `activityStub`, `await`, timers, `sideEffect`, enfants | Utiliser **`sideEffect()`** pour toute valeur non déterministe à figer dans le journal |

**Ne pas** appeler l’implémentation concrète d’une activité depuis le workflow : toujours passer par le **stub** obtenu via `activityStub(Interface::class)`.

## Déterminisme et effets de bord

À chaque reprise, le moteur **rejoue** le code du workflow. Les résultats d’activités déjà terminées sont **lus dans le journal**, pas réexécutés. Donc :

- Toute source non déterministe (hasard, `new DateTimeImmutable('now')`, lecture fichier, etc.) doit soit vivre dans une **activité**, soit être enveloppée dans **`WorkflowEnvironment::sideEffect()`** pour être enregistrée une fois et rejouée telle quelle.

Les **slots** (index séquentiels par famille d’opérations) permettent d’associer chaque opération durable à sa place dans l’historique — ne pas « court-circuiter » ce modèle en contournant l’API publique du workflow.

## Le modèle asynchrone « durable » (await / rejeu)

En PHP, le workflow utilise un style **async/await** au sens **durable** :

- Un appel via le stub d’activité ne « termine » pas comme un simple appel de fonction : le moteur peut **suspendre** le run après avoir planifié l’activité.
- **`$environment->await(...)`** attend la **promesse** liée à cette opération. Au **premier passage**, l’exécution peut s’arrêter après avoir écrit les événements nécessaires ; un worker d’activités mène le travail à bien.
- Au **replay**, la même ligne de code est exécutée, mais la valeur de retour est **reconstituée depuis le journal** : l’activité n’est pas rejouée côté domaine.

Ce n’est pas du multithreading classique : c’est une **coroutine logique** pilotée par le journal et les workers. D’où l’importance du déterminisme du code du workflow entre deux `await`.

## Options de nouvelle tentative et erreurs

Configurez les stubs dans le constructeur du workflow avec **`ActivityOptions`** : nombre max de tentatives, intervalles, exceptions **non retryables** (erreurs métier), etc.

```php
use Gplanchat\Durable\Activity\ActivityOptions;

$this->greeting = $environment->activityStub(
    GreetingActivityInterface::class,
    ActivityOptions::default()
        ->withMaxAttempts(3)
        ->withInitialInterval(2.0)
        ->withNonRetryableExceptions([\Domain\Exception\ValidationException::class]),
);
```

## Résumé

1. **Workflow** = orchestration déterministe + `await` sur des opérations durables.  
2. **Activité** = travail avec effets de bord, souvent **idempotent**, derrière une **interface**.  
3. **Séparation stricte** : le workflow ne connaît que le contrat ; les implémentations sont injectées côté infrastructure.  
4. **Asynchrone durable** = suspension + reprise + replay ; pas d’I/O non contrôlée dans le corps du workflow.

Pour la reprise distribuée (Messenger), les détails de dispatch sont décrits dans les ADR du dépôt (workflow run message, métadonnées, files d’activités).
