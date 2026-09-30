---
title: Écrire des activités
weight: 30
---

# Écrire des activités

Cette page résume comment on **écrit** des activités en Durable. Le détail normatif est dans [**DUR023**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR023-activity-authoring-and-asynchronous-activity-proxy.md) et [**DUR004**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR004-activity-stub-and-activities.md) ; ce guide reste pratique.

## Deux pièces

1. **L'interface de contrat d'activité.** Les méthodes que le workflow a le droit d'appeler, chacune marquée d'un **`#[AsActivityMethod]`**. Depuis le workflow, on passe par un **`ActivityStub`** (**ActivityInvoker** dans les ADR).
2. **La classe d'implémentation.** Une classe concrète portant **`#[AsActivityHandler]`**, qui nomme le contrat qu'elle implémente. Sous Symfony, c'est cet attribut qui l'enregistre : le bundle l'autoconfigure, et sans lui le workflow ne trouve aucun gestionnaire à l'exécution. Laravel liste la classe dans `activity_handlers` de `config/durable.php`, où l'attribut, s'il est présent, nomme le contrat qu'elle sert ; sans lui, la classe sert ses interfaces dont les méthodes portent `#[AsActivityMethod]`. Magento la liste dans l'argument `activityHandlers` de `RuntimeFactory` dans `di.xml`. Aucun des deux ne scanne les attributs : une classe non listée ne sert rien. Voir [qui enregistre quoi, par hôte](../getting-started/#déclarer-workflows-et-activités).

## Exemple : contrat et implémentation

L'**interface** énumère les méthodes que le workflow peut planifier. Chaque méthode exposée porte **`#[AsActivityMethod]`** avec un **nom d'activité stable** pour l'orchestrateur. La classe d'**implémentation** fait les E/S et peut recourir à l'**injection par constructeur**.

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityHandler;
use Gplanchat\Durable\Attribute\AsActivityMethod;

// `#[AsActivity]` est optionnel et se pose sur le **contrat** : il préfixe le nom des activités
// déclarées en dessous. Sur une classe d'implémentation, personne ne le lit.
#[AsActivity(name: 'order-activities')]
interface OrderActivities
{
    #[AsActivityMethod(name: 'charge-order')]
    public function charge(string $orderId): string; // type de retour synchrone, côté worker
}

#[AsActivityHandler(contract: OrderActivities::class)]
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

Depuis le workflow, vous n'employez jamais **`OrderActivitiesHandler`** directement. Déclarez un
stub du contrat en paramètre de la méthode de workflow, et faites **`await`** sur chaque appel. Un
appel sur le stub renvoie un **`Awaitable`**. Durable construit le stub et le passe à la méthode ;
le code qui démarre le workflow ne le passe jamais.

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** @param ActivityStub<OrderActivities> $activities */
#[AsWorkflowMethod]
public function run(
    string $orderId,
    #[Activities(OrderActivities::class)]
    ActivityStub $activities,
    WorkflowEnvironment $env,
): string {
    return $env->await($activities->charge($orderId));
}
```

Certains workflows construisent le stub eux-mêmes avec `$env->activityStub(OrderActivities::class)` :
voyez [Quand construire le stub soi-même](../workflows/#when-to-build-the-stub-yourself).

Le type **`ActivityStub`** (voir [Écrire un workflow](../workflows/) pour la note de nommage sur **ActivityInvoker**) résout les noms de méthode par réflexion sur **`OrderActivities`** et construit les charges utiles **`#[AsActivityMethod]`**.

## `ActivityOptions` : délais, réessais, file de tâches {#activityoptions-timeouts-retries-task-queue}

Donnez les options à **`#[Activities]`**. Tous les **`Awaitable`** que ce stub renvoie les
emploient au moment de planifier l'activité. Un argument d'attribut ne peut pas appeler de
constructeur : les durées s'écrivent donc en secondes.

```php
<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** @param ActivityStub<OrderActivities> $activities */
#[AsWorkflowMethod]
public function run(
    string $orderId,
    // 5 tentatives, 120 s chacune, 2 s avant le premier réessai.
    #[Activities(
        OrderActivities::class,
        attempts: 5,
        startToClose: 120.0,
        initialInterval: 2.0,
        nonRetryable: [PaymentRefusedException::class],
        summary: 'Charge order payment',
    )]
    ActivityStub $activities,
    WorkflowEnvironment $env,
): string {
    return $env->await($activities->charge($orderId));
}
```

Quand les options dépendent de l'entrée du workflow, construisez-les sous forme d'objet valeur
**`ActivityOptions`** et passez-le en second argument de `$env->activityStub()`. Limites de réessai
et durées sont alors des **objets valeur**, pas des nombres ; voyez
[Options et objets valeur](../options/).

> [!WARNING]
> Sans `RetryLimit`, les tentatives sont **illimitées**, c'est le défaut de Temporal. Une activité
> qui échoue systématiquement réessaiera indéfiniment au lieu de faire échouer le workflow. Passez
> `RetryLimit::once()` quand un échec doit être définitif.

> [!NOTE]
> **Deux délais, deux propriétaires.** `ActivityTimeouts` borne une **tentative** d'activité et est
> appliqué par le **backend** : il survit au plantage d'un worker, et il ne concerne que cette
> activité-là. Une **échéance** passée à `await()`, sur un awaitable ou sur une condition, est
> appliquée **côté workflow** : elle borne *cette* attente dans *cette* exécution, et elle couvre
> ce que les bornes d'activité ne savent pas couvrir : un workflow enfant, un signal, un groupe
> composé. Prenez `ActivityTimeouts` pour borner une tentative, et une échéance pour borner tout le
> reste. Voir [Borner une attente dans le temps](../workflows/#bounding-a-wait-in-time).

Déclarez des **stubs distincts** quand deux appels ont besoin de politiques différentes : l'un avec
des réessais agressifs pour un appel HTTP capricieux, l'autre avec des délais plus stricts pour un
chemin rapide :

```php
/**
 * @param ActivityStub<SearchActivities>  $flaky
 * @param ActivityStub<PricingActivities> $strict
 */
#[AsWorkflowMethod]
public function run(
    string $query,
    #[Activities(SearchActivities::class, attempts: 10, initialInterval: 0.2)]
    ActivityStub $flaky,
    #[Activities(PricingActivities::class, attempts: 1, startToClose: 2.0)]
    ActivityStub $strict,
    WorkflowEnvironment $env,
): array {
    // ...
}
```

> [!NOTE]
> Le docblock **`@param ActivityStub<Contrat>`** est ce qui permet à
> [`gplanchat/durable-phpstan`](https://github.com/gplanchat/durable-phpstan) de vérifier les
> appels que vous passez par le stub. PHP n'a pas de génériques à l'exécution : l'attribut nomme le
> contrat pour Durable, le docblock le nomme pour PHPStan, et l'extension signale un docblock qui
> nomme un autre contrat que l'attribut. Un stub que vous construisez vous-même dans une propriété
> **`readonly`** n'a pas besoin de docblock : PHPStan déduit le contrat depuis `activityStub()`.
> Dans tous les cas, un contrat qu'il ne peut pas résoudre laisse l'appel inconnu de l'analyseur,
> jamais silencieusement accepté.


## Idempotence

Le journal empêche une activité **terminée** de s'exécuter à nouveau. Il ne peut rien pour une
tentative qui s'arrête entre son effet de bord et l'enregistrement de son résultat : le prestataire
de paiement a débité la carte, puis la tentative a expiré ou le worker est mort. Cette tentative a
échoué et elle est réessayée. Une activité s'exécute **au moins une fois**.

Tout ce qu'une activité fait au monde extérieur a donc besoin d'une clé identique d'une tentative à
l'autre. Le workflow passe les mêmes arguments à chaque tentative : construisez la clé à partir
d'eux et d'un préfixe fixe qui nomme l'opération (`charge-`, `refund-`), jamais d'une valeur
aléatoire ni de l'heure :

```php
public function charge(string $orderId): string
{
    return $this->psp->charge($orderId, idempotencyKey: 'charge-' . $orderId);
}
```

La clé est la même pour toutes les tentatives d'une même opération, et différente pour deux
opérations distinctes. `charge-<orderId>` ne convient que si une commande n'est débitée qu'une fois.
Si elle peut l'être de nouveau (une seconde échéance, une nouvelle exécution pour la même commande),
ajoutez ce qui distingue les débits, comme le numéro d'échéance. Vérifiez aussi combien de temps
votre prestataire retient une clé.

Un `RetryLimit` limite le nombre de tentatives qui atteignent le prestataire ; il ne rend pas la
deuxième sûre. Avec `RetryLimit::once()`, une tentative interrompue n'est pas réessayée : l'appel a
pu avoir lieu ou non, et le workflow voit un échec.

## Injection de dépendances

Contrairement aux workflows, l'**implémentation d'activité** **peut** avoir un constructeur ordinaire avec **injection de dépendances** : clients HTTP, bases de données, journaux, etc., tels que les fournit l'hôte du **worker d'activités** (par exemple le conteneur Symfony dans le processus du worker).

### Battements de cœur : une activité longue dit qu'elle est vivante

Une activité longue injecte `ActivityHeartbeatSenderInterface` et appelle `sendHeartbeat()` entre
deux étapes. L'appel rend `true` dès qu'une annulation a été demandée : arrêtez-vous là et
nettoyez.

```php
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;

final class ImportCatalog implements CatalogActivities
{
    public function __construct(
        private readonly ActivityHeartbeatSenderInterface $heartbeat,
        private readonly CatalogReader $reader,
    ) {}

    public function import(string $file): int
    {
        $count = 0;
        foreach ($this->reader->batches($file) as $batch) {
            $count += $this->reader->store($batch);
            if ($this->heartbeat->sendHeartbeat(['imported' => $count])) {
                break; // annulée : s'arrêter entre deux lots, jamais au milieu d'un lot
            }
        }

        return $count;
    }
}
```

Sur Temporal, quel que soit l'hôte, le battement réarme le délai `heartbeat` de l'activité (voir
[ActivityTimeouts](../options/#activitytimeouts)) et transporte le détail de progression. Sur les
autres backends, c'est un appel sans effet qui ne signale jamais d'annulation : le même code tourne
partout.

## Côté workflow : `ActivityInvoker`

Depuis **`WorkflowEnvironment`** (voir [Écrire un workflow](../workflows/)), vous appelez **`activityStub(VotreInterfaceDActivité::class)`** et vous obtenez un **`ActivityStub`** (même notion que l'**`ActivityInvoker`** des ADR).

Un stub qui n'a pas besoin d'**`ActivityOptions`** peut aussi se déclarer en argument de la méthode de workflow : un paramètre typé **`ActivityStub`** et marqué **`#[Activities(VotreInterfaceDActivité::class)]`** reçoit le même stub. Voir [Les arguments que fournit Durable](../workflows/#arguments-durable-supplies).

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
| Workflow | N'emploie qu'**`activityStub()`** / **`ActivityStub`** depuis **`WorkflowEnvironment`**, ou un argument **`#[Activities]`** ; jamais un `new` sur la classe d'activité pour un effet durable ; second argument facultatif **`ActivityOptions`** |

## Voir aussi

- [Écrire un workflow](../workflows/) couvre **`WorkflowEnvironment`** et **`ActivityInvoker`**.
- [Concepts](../concepts/) explique pourquoi les activités portent les effets de bord et le rejeu.
