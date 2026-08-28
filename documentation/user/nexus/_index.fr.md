---
title: Opérations Nexus
weight: 29
---

# Opérations Nexus

Nexus permet à un workflow d'appeler une opération qui appartient à une autre équipe, un autre
namespace, un autre déploiement — sans qu'aucun des deux côtés connaisse les workflows de l'autre.
Durable tient les deux rôles : il **appelle** des opérations, et il en **sert**.

Servir demande le **backend Temporal**. Les backends in-memory et DBAL n'ont aucune route entre
namespaces, et ils le disent plutôt que de faire semblant — voir [Backends](../backends/).

---

## Appeler une opération

```php
#[AsNexusService('facturation')]
interface FacturationContract
{
    #[AsNexusOperation('encaisser')]
    public function encaisser(Ordre $ordre, int $montant): Recu;
}
```

```php
$facturation = $env->nexusStub(FacturationContract::class, endpoint: 'paiements');

$recu = $env->await($facturation->encaisser($ordre, 1200));
```

Le contrat s'écrit **une fois** et se lit des deux côtés de la frontière : l'appelant en dérive un
stub typé, le gestionnaire l'implémente. Aucun nom d'opération n'est recopié en chaîne, si bien
qu'une faute de frappe est une erreur de type et non une opération qui attend un gestionnaire dont
le nom ne correspondra jamais.

L'endpoint est un paramètre du stub, pas du contrat : il dit *où* le service est servi, ce qui
relève du déploiement et change d'un environnement à l'autre, quand le contrat ne change pas.

`nexusStub()` assemble ; `await()` attend. C'est la règle partout ailleurs — voir
[Créer un workflow](../workflows/).

La charge voyage **telle que vous l'avez écrite**. Aucune enveloppe Durable ne l'entoure, donc un
gestionnaire écrit avec le SDK Go, Java ou TypeScript y lit les champs qu'il déclare.

Que le gestionnaire réponde tout de suite ou dans deux heures ne change rien ici : le workflow
attend l'opération, et le résultat arrive quand il arrive.

---

## Servir une opération

Un gestionnaire implémente le contrat — ou la part de celui-ci à laquelle il répond tout de suite :

```php
use Gplanchat\Durable\Attribute\AsNexusServiceHandler;

#[AsNexusServiceHandler(contract: FacturationServie::class)]
final class Facturation implements FacturationServie
{
    public function verifier(Ordre $ordre): Verdict
    {
        return $this->regles->controler($ordre);
    }
}
```

### Pourquoi le contrat vient en deux morceaux

Une opération remplie par un workflow n'a pas de corps de gestionnaire — la plomberie démarre le
workflow, et le serveur en livre le résultat. Le contrat se sépare donc : l'interface qu'un
gestionnaire **implémente**, et celle qui l'**étend** pour l'appelant.

```php
#[AsNexusService('facturation')]
interface FacturationServie                            // répondu tout de suite
{
    #[AsNexusOperation('verifier')]
    public function verifier(Ordre $ordre): Verdict;
}

#[AsNexusService('facturation')]
interface FacturationContract extends FacturationServie // + ce qu'un workflow remplit
{
    #[AsNexusOperation('encaisser')]
    public function encaisser(Ordre $ordre, int $montant): Recu;
}

#[AsWorkflow]
#[FulfilsNexusOperation(FacturationContract::class, 'encaisser')]
final class Encaissement { /* … */ }
```

Sans cette séparation, PHP exigerait un corps pour `encaisser()` sur le gestionnaire — une méthode
vide dont le seul rôle serait de dire qu'il n'y a rien à écrire. C'est le workflow qui réclame
l'opération, là où son code vit, et le contrat de l'appelant déclare quand même tout, pour que le
stub puisse tout appeler.

### Répondre maintenant, ou répondre plus tard

Il y a deux formes, et choisir entre elles est la seule décision qui compte.

```php
// Maintenant — le gestionnaire rend le type déclaré par le contrat.
public function verifier(Ordre $ordre): Verdict { … }

// Plus tard — un workflow réclame l'opération, et produit le résultat.
#[FulfilsNexusOperation(FacturationContract::class, 'encaisser')]
final class Encaissement { … }
```

**Un gestionnaire dispose d'environ neuf secondes.** Ce n'est pas le budget de l'opération, c'est
celui pour répondre à *cette tâche* : le `scheduleToClose` de l'appelant peut valoir cinq minutes,
la tâche elle-même porte un `request-timeout` d'environ neuf secondes. Un gestionnaire encore au
travail quand il expire voit sa tâche redélivrée — et recommence. Redélivrances mesurées : ~9,9 s,
~20,7 s, ~33,6 s.

Une méthode implémentée est donc pour une lecture, une validation, un calcul dont vous savez qu'il
est rapide. Tout ce qui parle à un prestataire de paiement, attend un humain ou réessaie pendant une
journée appartient à un workflow — et c'est ce que déclare `#[FulfilsNexusOperation]`.

Quand vous nommez un workflow, Durable le démarre avec le callback de l'appelant attaché, et le
serveur livre le résultat de ce workflow à l'appelant quand il se termine. Votre gestionnaire n'est
pas rappelé.

### Annulation

Si l'appelant annule, Durable annule le workflow qui remplit l'opération. Vous n'écrivez aucun
crochet d'annulation : votre workflow observe déjà son annulation et compense, exactement comme
décrit dans [Annulation](../cancellation/).

Une annulation n'atteint un gestionnaire que pour une opération **démarrée** — une opération qui
attend encore sa première réponse n'a rien à annuler de votre côté.

### Échouer

Levez, et l'opération échoue :

```php
throw new \RuntimeException('le prestataire de paiement est injoignable');
```

Une exception ordinaire est rapportée en `INTERNAL`, qui est **réessayable** — la tâche revient,
jusqu'au budget de l'opération. C'est juste pour une panne et faux pour une requête invalide, qui
ne s'améliorera jamais. Pour un refus définitif, dites de quelle nature il est :

| définitif — ne pas réessayer | réessayable — retenter |
|---|---|
| `BAD_REQUEST`, `UNAUTHENTICATED`, `UNAUTHORIZED` | `RESOURCE_EXHAUSTED`, `INTERNAL` |
| `NOT_FOUND`, `NOT_IMPLEMENTED`, `CONFLICT` | `UNAVAILABLE`, `UPSTREAM_TIMEOUT`, `REQUEST_TIMEOUT` |

La ligne de partage est *à qui la faute*. Une requête malformée ou un droit manquant ne se répare
pas en réessayant ; une surcharge ou un délai en amont, peut-être. La table est celle de nexus-rpc,
partagée par tous les SDK — ce n'est pas une invention de Durable.

Une opération que personne ne sert reçoit `NOT_IMPLEMENTED`, définitif, et le worker continue de
servir les autres.

---

## Lancer le worker

Servir demande un worker sur la file de tâches Nexus. C'est un transport Messenger, comme le worker
d'activité :

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            durable_temporal_nexus:
                dsn: '%env(DURABLE_DSN)%'
                options:
                    purpose: nexus_worker
```

```bash
php bin/console messenger:consume durable_temporal_nexus --time-limit=3600
```

La file vient du DSN. `nexus_task_queue` la fixe ; **par défaut elle suit la file de workflow**,
parce qu'un endpoint Nexus vise une file et que le serveur n'y livre que si quelqu'un y poll. Une
file que personne ne sert est un endpoint qui ne répond jamais, sans erreur nulle part.

---

## Enregistrer l'endpoint

Un endpoint est un objet du cluster entier, créé une fois par un opérateur, pas par l'application :

```bash
temporal operator nexus endpoint create \
    --name paiements \
    --target-namespace production \
    --target-task-queue durable-workflows
```

Le `--target-task-queue` doit être la file que votre worker Nexus poll.

---

## Si vous déclarez un gestionnaire sur le mauvais backend

Le conteneur refuse de se construire, et nomme ce qui manque :

```
durable.nexus_handler: a Nexus handler is declared, but this backend cannot route
Nexus operations. Nexus needs the Temporal backend — set durable.temporal.dsn.
Declared by: app.encaisser.
```

C'est délibéré, et ce n'est pas ainsi que se comporte le côté appelant. Un appel sur un backend sans
route échoue à l'appel — vous l'apprenez tout de suite. Un *gestionnaire* sans route n'est pas un
appel qui échoue, c'est un service qui ne reçoit jamais rien, en silence. Il ne reste aucune requête
à faire échouer, alors le refus a lieu au démarrage de l'application.

---

---

## Un exemple complet, des deux côtés

Deux équipes. **Facturation** sert les opérations ; **Commande** les appelle. Aucune ne connaît les
workflows de l'autre — c'est à cela que sert Nexus. Chaque classe ci-dessous est complète.

### Le contrat, partagé par les deux côtés

Un fichier, publié comme une petite bibliothèque dont les deux équipes dépendent. C'est la seule
chose qu'elles partagent.

```php
<?php

declare(strict_types=1);

namespace Acme\Billing\Contract;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;

/** Ce que Facturation répond tout de suite. C'est celle-ci qu'un gestionnaire implémente. */
#[AsNexusService('billing')]
interface BillingServed
{
    #[AsNexusOperation('quote')]
    public function quote(string $sku, int $quantity): int;
}

/** Tout ce que Facturation sert. C'est celle-ci qu'un appelant lit. */
#[AsNexusService('billing')]
interface BillingContract extends BillingServed
{
    #[AsNexusOperation('charge')]
    public function charge(string $orderId, int $amountInCents): string;
}
```

`charge` n'a de corps nulle part : un workflow la remplit. `quote` est une lecture, elle est donc
implémentée. Cette séparation est ce qui évite à l'une comme à l'autre d'avoir une méthode vide.

### Facturation — le gestionnaire

```php
<?php

declare(strict_types=1);

namespace Acme\Billing\Nexus;

use Acme\Billing\Contract\BillingServed;
use Acme\Billing\PriceList;
use Gplanchat\Durable\Attribute\AsNexusServiceHandler;

#[AsNexusServiceHandler(contract: BillingServed::class)]
final class BillingHandler implements BillingServed
{
    public function __construct(
        private readonly PriceList $prices,
    ) {}

    /**
     * Répond sur la tâche elle-même : il lui faut donc rendre bien avant neuf secondes.
     * Une lecture de tarif le fait ; un prestataire de paiement, non.
     */
    public function quote(string $sku, int $quantity): int
    {
        return $this->prices->unitPriceOf($sku) * $quantity;
    }
}
```

### Facturation — le workflow qui remplit `charge`

```php
<?php

declare(strict_types=1);

namespace Acme\Billing\Workflow;

use Acme\Billing\Contract\BillingContract;
use Acme\Billing\Contract\PaymentActivities;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('Charge')]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class ChargeWorkflow
{
    /** @var ActivityStub<PaymentActivities> */
    private readonly ActivityStub $payments;

    public function __construct(
        private readonly WorkflowEnvironment $env,
    ) {
        $this->payments = $env->activityStub(PaymentActivities::class);
    }

    /**
     * La charge de l'appelant arrive comme arguments de cette méthode, et ce qu'elle
     * rend devient le résultat de l'opération — quel que soit le temps que ça prend.
     */
    #[AsWorkflowMethod]
    public function run(string $orderId, int $amountInCents): string
    {
        $authorisation = $this->env->await(
            $this->payments->authorise($orderId, $amountInCents),
        );

        // Des heures peuvent passer ici. L'appelant ne tient rien d'ouvert.
        $this->env->await($this->env->timer(Duration::hours(2)));

        return $this->env->await($this->payments->capture($authorisation));
    }
}
```

### Commande — l'appelant

```php
<?php

declare(strict_types=1);

namespace Acme\Checkout\Workflow;

use Acme\Billing\Contract\BillingContract;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow('Checkout')]
final class CheckoutWorkflow
{
    /** @var NexusStub<BillingContract> */
    private readonly NexusStub $billing;

    public function __construct(
        private readonly WorkflowEnvironment $env,
    ) {
        $this->billing = $env->nexusStub(
            BillingContract::class,
            endpoint: 'billing-endpoint',
            timeouts: new NexusOperationTimeouts(scheduleToClose: Duration::hours(6)),
        );
    }

    #[AsWorkflowMethod]
    public function run(string $orderId, string $sku, int $quantity): string
    {
        // Immédiate : Facturation l'implémente.
        $total = $this->env->await($this->billing->quote($sku, $quantity));

        // Différée : un workflow de Facturation la produit, des heures plus tard.
        // Rien ici ne dit laquelle est laquelle — et rien n'a à le dire.
        return $this->env->await($this->billing->charge($orderId, $total));
    }
}
```

**L'appelant ne peut pas distinguer les deux, et c'est tout l'intérêt.** Que Facturation réponde sur
la tâche ou confie le travail à un workflow est sa décision, modifiable sans toucher à Commande.

> **Les noms de paramètres doivent coïncider.** Le stub de l'appelant construit la charge à partir
> des noms de paramètres de la *méthode du contrat* — `charge(string $orderId, int $amountInCents)`
> envoie `{"orderId": …, "amountInCents": …}` —, et la méthode du workflow qui remplit l'opération
> est garnie par ces mêmes noms. Renommez d'un seul côté et le workflow reçoit `null`, en silence :
> une clé absente ne se distingue pas d'un argument qu'on n'a pas envoyé. C'est le contrat qui tient
> les deux honnêtes — changez-le une fois, et les deux côtés cessent de compiler plutôt que de
> défaillir à l'exécution.

### Le faire tourner

Facturation a besoin du worker Nexus sur sa file :

```bash
php bin/console messenger:consume durable_temporal_nexus --time-limit=3600
```

Et un opérateur crée l'endpoint une fois, en visant la file que ce worker interroge :

```bash
temporal operator nexus endpoint create \
    --name billing-endpoint \
    --target-namespace billing-prod \
    --target-task-queue durable-workflows
```

---

## Voir aussi

- [Backends](../backends/) — quel backend sait router Nexus, et pourquoi les autres refusent.
- [Annulation](../cancellation/) — ce que fait votre workflow quand l'appelant annule.
- [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md) — la décision, et les mesures derrière.
