---
title: Écrire des activités
weight: 30
---

# Écrire des activités

Cette page résume comment on **écrit** des activités en Durable. Le détail normatif est dans [**DUR023**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR023-activity-authoring-and-asynchronous-activity-proxy.md) et [**DUR004**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR004-activity-stub-and-activities.md) ; ce guide reste pratique.

## Deux pièces

1. **L'interface de contrat d'activité** — les méthodes que le workflow a le droit d'appeler, chacune marquée d'un **`#[AsActivityMethod]`**. Depuis le workflow, on passe par un **`ActivityStub`** (**ActivityInvoker** dans les ADR).
2. **La classe d'implémentation** — une classe concrète (souvent annotée d'un **`#[AsActivity]`** pour son nom) qui **implémente** le contrat et fait le vrai travail.

## Exemple : contrat et implémentation

L'**interface** énumère les méthodes que le workflow peut planifier. Chaque méthode exposée porte **`#[AsActivityMethod]`** avec un **nom d'activité stable** pour l'orchestrateur. La classe d'**implémentation** fait les E/S et peut recourir à l'**injection par constructeur**.

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;

interface OrderActivities
{
    #[AsActivityMethod(name: 'charge-order')]
    public function charge(string $orderId): string; // type de retour synchrone, côté worker
}

#[AsActivity(name: 'order-activities')]
final class OrderActivitiesHandler implements OrderActivities
{
    public function __construct(
        private readonly PaymentGatewayClient $payments,
    ) {
    }

    public function charge(string $orderId): string
    {
        return $this->payments->capture($orderId);
    }
}
```

Déclarez **`OrderActivitiesHandler`** auprès de votre worker d'activités ou de votre conteneur, pour que le worker puisse exécuter **`charge-order`** quand le workflow la planifie.

## Exemple : appeler une activité depuis un workflow

Depuis le workflow, vous n'employez jamais **`OrderActivitiesHandler`** directement. Vous obtenez un stub de **`WorkflowEnvironment`** et vous **`await`** l'appel (le stub renvoie un **`Awaitable`**).

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\WorkflowEnvironment;

// À l'intérieur d'une #[AsWorkflowMethod] de votre classe de workflow :
$activities = $this->environment->activityStub(OrderActivities::class);

$receipt = $this->environment->await($activities->charge($orderId));
```

Le type **`ActivityStub`** (voir [Écrire un workflow](../workflows/) pour la note de nommage sur **ActivityInvoker**) résout les noms de méthode par réflexion sur **`OrderActivities`** et construit les charges utiles **`#[AsActivityMethod]`**.

## `ActivityOptions` : délais, réessais, file de tâches {#activityoptions-timeouts-retries-task-queue}

Passez des **`ActivityOptions`** en **second argument** d'**`activityStub()`**. Tous les **`Awaitable`** que ce stub renvoie emploieront ces réglages au moment de planifier l'activité.

Limites de réessai et durées sont des **objets valeur**, pas des nombres — voir [Options et objets valeur](../options/).

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\ActivityOptions;

// 5 tentatives, 120 s chacune, 2 s avant le premier réessai.
$options = ActivityOptions::of(
    5,
    120,
    2,
    [PaymentRefusedException::class],
    summary: 'Charge order payment',
);

$activities = $this->environment->activityStub(OrderActivities::class, $options);

$result = $this->environment->await($activities->charge($orderId));
```

> [!WARNING]
> Sans `RetryLimit`, les tentatives sont **illimitées** — c'est le défaut de Temporal. Une activité
> qui échoue systématiquement réessaiera indéfiniment au lieu de faire échouer le workflow. Passez
> `RetryLimit::once()` quand un échec doit être définitif.

> [!NOTE]
> **Deux délais, deux propriétaires.** `ActivityTimeouts` borne une **tentative** d'activité et est
> appliqué par le **backend** : il survit au plantage d'un worker, et il ne concerne que cette
> activité-là. Une **échéance** passée à `await()` — sur un awaitable ou sur une condition — est
> appliquée **côté workflow** : elle borne *cette* attente dans *cette* exécution, et elle couvre
> ce que les bornes d'activité ne savent pas couvrir — un workflow enfant, un signal, un groupe
> composé. Prenez `ActivityTimeouts` pour borner une tentative, et une échéance pour borner tout le
> reste. Voir [Borner une attente dans le temps](../workflows/#bounding-a-wait-in-time).

Créez des **stubs distincts** quand deux appels ont besoin de politiques différentes — l'un avec
des réessais agressifs pour un appel HTTP capricieux, l'autre avec des délais plus stricts pour un
chemin rapide :

```php
/** @var ActivityStub<SearchActivities> */
private readonly ActivityStub $flaky;

/** @var ActivityStub<PricingActivities> */
private readonly ActivityStub $strict;

// … dans le constructeur :
$this->flaky  = $env->activityStub(SearchActivities::class, ActivityOptions::of(
    10,
    initialInterval: Duration::milliseconds(200),
));

$this->strict = $env->activityStub(PricingActivities::class, ActivityOptions::of(
    RetryLimit::once(),
    timeouts: 2,
));
```

> [!NOTE]
> Déclarer la propriété **`readonly`** est ce qui permet à
> [`gplanchat/durable-phpstan`](https://github.com/gplanchat/durable-phpstan) de vérifier les
> appels que vous passez par le stub : PHPStan déduit le contrat depuis `activityStub()` et sait le
> suivre jusqu'au site d'appel. Une propriété mutable le lui fait perdre, et il faut alors un
> `/** @var ActivityStub<Contrat> */` explicite. Dans tous les cas, un contrat qu'il ne peut pas
> résoudre laisse l'appel inconnu de l'analyseur — jamais silencieusement accepté.


## Injection de dépendances

Contrairement aux workflows, l'**implémentation d'activité** **peut** avoir un constructeur ordinaire avec **injection de dépendances** : clients HTTP, bases de données, journaux, etc., tels que les fournit l'hôte du **worker d'activités** (par exemple le conteneur Symfony dans le processus du worker).

## Côté workflow : `ActivityInvoker`

Depuis **`WorkflowEnvironment`** (voir [Écrire un workflow](../workflows/)), vous appelez **`activityStub(VotreInterfaceDActivité::class)`** et vous obtenez un **`ActivityStub`** (même notion que l'**`ActivityInvoker`** des ADR).

- Pour chaque **`#[AsActivityMethod]`** de l'interface, le stub expose **le même nom de méthode et les mêmes paramètres** ; chaque appel renvoie un **`Awaitable`** que vous passez à **`$environment->await(...)`** (le type de retour synchrone **`T`** de l'interface est ce que vous obtenez après l'**`await`**).
- L'invocateur **n'exécute pas** d'E/S dans le processus du workflow : il **planifie** une étape durable et rattache le résultat à l'historique et au rejeu.

C'est cette séparation qui garde le code de workflow déterministe pendant que les activités font le travail non déterministe.

## Sérialisation

Arguments et valeurs de retour doivent être **sérialisables** au passage de la frontière de l'orchestrateur (**DUR007**). Évitez les ressources brutes, les fermetures non prises en charge, ou les types que votre sérialiseur configuré ne sait pas traiter.

## Aide-mémoire

| Pièce | Responsabilité |
|-------|----------------|
| Interface | `#[AsActivityMethod]` sur les méthodes appelables ; des types sérialisables |
| Implémentation | E/S et injection de dépendances ; implémente l'interface |
| Workflow | N'emploie qu'**`activityStub()`** / **`ActivityStub`** depuis **`WorkflowEnvironment`** — jamais un `new` sur la classe d'activité pour un effet durable ; second argument facultatif **`ActivityOptions`** |

## Voir aussi

- [Écrire un workflow](../workflows/) — **`WorkflowEnvironment`** et **`ActivityInvoker`**.
- [Concepts](../concepts/) — pourquoi les activités portent les effets de bord et le rejeu.
