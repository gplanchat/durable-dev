---
title: Écrire des activités
weight: 30
---

# Écrire des activités

Cette page montre comment **écrire** une activité, c'est-à-dire une unité d'effet de bord comme un appel HTTP, une écriture en base ou un e-mail (voir le [glossaire](../glossary/)), et comment l'appeler depuis un workflow. Le détail normatif est dans [**DUR023**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR023-activity-authoring-and-asynchronous-activity-proxy.md) et [**DUR004**](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR004-activity-stub-and-activities.md) ; cette page s'en tient à la pratique.

## L'interface de contrat et la classe d'implémentation {#deux-pièces}

Une activité se compose de deux éléments.

1. **L'interface de contrat d'activité.** Elle énumère les méthodes que le workflow peut appeler, chacune marquée d'un **`#[AsActivityMethod]`**. Le workflow les appelle par un **`ActivityStub`**, un objet qui expose au workflow les méthodes du contrat (**ActivityInvoker** dans les ADR).
2. **La classe d'implémentation.** Une classe concrète portant **`#[AsActivityHandler]`**, qui nomme le contrat qu'elle implémente. Sous Symfony, c'est cet attribut qui l'enregistre : le bundle l'autoconfigure, et sans lui le workflow ne trouve aucun gestionnaire à l'exécution. Laravel liste la classe dans `activity_handlers` de `config/durable.php`, où l'attribut, s'il est présent, nomme le contrat qu'elle sert ; sans lui, la classe sert ses interfaces dont les méthodes portent `#[AsActivityMethod]`. Magento la liste dans l'argument `activityHandlers` de `RuntimeFactory` dans `di.xml`. Aucun de ces deux hôtes ne scanne les attributs : une classe absente de la liste ne sert donc rien. Voir [qui enregistre quoi, par hôte](../getting-started/#déclarer-workflows-et-activités).

## Exemple : contrat et implémentation

L'**interface** énumère les méthodes que le workflow peut planifier. Chaque méthode exposée porte **`#[AsActivityMethod]`** avec un **nom d'activité stable** pour l'orchestrateur. La classe d'**implémentation** effectue les E/S et peut recevoir ses dépendances par **injection dans le constructeur**.

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

Déclarez **`OrderActivitiesHandler`** auprès de votre conteneur ou de votre worker d'activités (le processus qui exécute les activités), pour qu'il puisse exécuter **`charge-order`** quand le workflow la planifie.

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
[`gplanchat/durable-phpstan`](https://github.com/gplanchat/durable-phpstan) signale un stub
construit qui pourrait être un paramètre `#[Activities]`, avec l'attribut à écrire, sous
l'identifiant `durable.activityStubCouldBeParameter`.

Le type **`ActivityStub`** résout les noms de méthode par réflexion sur **`OrderActivities`** et construit les charges utiles **`#[AsActivityMethod]`**. [Écrire un workflow](../workflows/) explique le nom **ActivityInvoker**.

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
> Sans `RetryLimit`, les tentatives sont **illimitées**, comme par défaut dans Temporal. Une
> activité qui échoue systématiquement réessaie indéfiniment, et le workflow n'échoue pas. Passez
> `RetryLimit::once()` quand un échec doit être définitif.

> [!NOTE]
> **Délais d'activité et échéances.** `ActivityTimeouts` borne une **tentative** d'activité, et
> c'est le **backend** qui l'applique. Il survit au plantage d'un worker et ne concerne que cette
> activité-là. Une **échéance** passée à `await()`, sur un awaitable ou sur une condition,
> s'applique **côté workflow**. Elle borne *cette* attente dans *cette* exécution, et couvre aussi
> des attentes que les délais d'activité ne couvrent pas, comme un workflow enfant, un signal ou un
> groupe composé. Employez `ActivityTimeouts` pour borner une tentative, et une échéance pour borner
> toute autre attente. Voir [Borner une attente dans le temps](../workflows/#bounding-a-wait-in-time).

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

Le journal, qui enregistre les étapes d'une exécution et leurs résultats, empêche une activité
**terminée** de s'exécuter à nouveau. Il ne couvre pas une tentative qui s'arrête entre son effet
de bord et l'enregistrement de son résultat. Par exemple, le prestataire de paiement débite la
carte, puis la tentative expire ou le worker meurt. Cette tentative compte comme un échec, et elle
est réessayée. Une activité s'exécute **au moins une fois**.

Tout ce qu'une activité fait au monde extérieur a donc besoin d'une clé identique d'une
tentative à l'autre. Le workflow passe les mêmes arguments à chaque tentative. Construisez la clé à
partir de ces arguments et d'un préfixe fixe qui nomme l'opération (`charge-`, `refund-`), jamais
d'une valeur aléatoire ni de l'heure courante :

```php
public function charge(string $orderId): string
{
    return $this->psp->charge($orderId, idempotencyKey: 'charge-' . $orderId);
}
```

La clé est la même pour toutes les tentatives d'une même opération, et différente pour deux
opérations distinctes. `charge-<orderId>` ne convient que si une commande n'est débitée qu'une fois.
Si elle peut l'être de nouveau (une seconde échéance, une nouvelle exécution pour la même commande),
ajoutez la valeur qui distingue les débits, comme le numéro d'échéance. Vérifiez aussi combien de
temps votre prestataire conserve une clé.

Un `RetryLimit` limite le nombre de tentatives qui atteignent le prestataire. Il ne rend pas une
deuxième tentative sûre. Avec `RetryLimit::once()`, une tentative interrompue n'est pas
réessayée. L'appel a pu avoir lieu ou non, et le workflow reçoit un échec.

## Injection de dépendances

Contrairement à un workflow, une **implémentation d'activité** **peut** avoir un constructeur ordinaire avec **injection de dépendances**. Elle reçoit des clients HTTP, des bases de données, des loggers et d'autres services de l'hôte du **worker d'activités**, par exemple du conteneur Symfony dans le processus du worker.

### Battements de cœur d'une activité longue {#battements-de-cœur--une-activité-longue-dit-quelle-est-vivante}

Pour signaler qu'une activité longue est toujours en vie, injectez `ActivityHeartbeatSenderInterface`
et appelez `sendHeartbeat()` entre deux étapes. L'appel renvoie `true` dès qu'une annulation a été
demandée. Dans ce cas, arrêtez-vous à cet endroit et nettoyez.

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
autres backends, l'appel n'a aucun effet et ne signale jamais d'annulation : le même code tourne
donc sur tous les backends.

## Côté workflow : `ActivityInvoker`

Depuis **`WorkflowEnvironment`** (voir [Écrire un workflow](../workflows/)), appelez **`activityStub(VotreInterfaceDActivité::class)`** pour obtenir un **`ActivityStub`**, la notion que les ADR nomment **`ActivityInvoker`**.

Un stub qui n'a pas besoin d'**`ActivityOptions`** peut aussi se déclarer en argument de la méthode de workflow : un paramètre typé **`ActivityStub`** et marqué **`#[Activities(VotreInterfaceDActivité::class)]`** reçoit le même stub. Voir [Les arguments que fournit Durable](../workflows/#arguments-durable-supplies).

- Pour chaque **`#[AsActivityMethod]`** de l'interface, le stub expose **le même nom de méthode et les mêmes paramètres** ; chaque appel renvoie un **`Awaitable`** que vous passez à **`$environment->await(...)`** (le type de retour synchrone **`T`** de l'interface est ce que vous obtenez après l'**`await`**).
- L'invocateur **n'exécute pas** d'E/S dans le processus du workflow. Il **planifie** une étape durable et rattache son résultat à l'historique et au rejeu (la réexécution de la méthode du workflow depuis sa première ligne, où chaque étape enregistrée renvoie son résultat).

Cette séparation garde le code du workflow déterministe, pendant que les activités font le travail non déterministe.

## Sérialisation

Les arguments et les valeurs de retour franchissent la frontière de l'orchestrateur : ils doivent donc être **sérialisables** (**DUR007**). Ne passez ni ressource brute, ni fermeture non prise en charge, ni type que votre sérialiseur configuré ne traite pas.

## Aide-mémoire

| Pièce | Responsabilité |
|-------|----------------|
| Interface | `#[AsActivityMethod]` sur les méthodes appelables ; des types sérialisables |
| Implémentation | E/S et injection de dépendances ; implémente l'interface |
| Workflow | N'emploie qu'**`activityStub()`** / **`ActivityStub`** depuis **`WorkflowEnvironment`**, ou un argument **`#[Activities]`** ; jamais un `new` sur la classe d'activité pour un effet durable ; second argument facultatif **`ActivityOptions`** |

## Voir aussi

- [Écrire un workflow](../workflows/) couvre **`WorkflowEnvironment`** et **`ActivityInvoker`**.
- [Concepts](../concepts/) explique pourquoi les activités portent les effets de bord et le rejeu.
