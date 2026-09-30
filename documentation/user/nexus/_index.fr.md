---
title: Opérations Nexus
weight: 29
---

# Opérations Nexus

Une opération Nexus est une opération servie par un autre service, avec son propre contrat, qu'un
workflow appelle comme il appelle une activité (voir le [glossaire](../glossary/)). Avec Nexus, un
workflow appelle une opération qui appartient à une autre équipe, à un autre namespace ou à un
autre déploiement, et aucun des deux côtés ne connaît les workflows de l'autre. Durable tient les
deux rôles : il **appelle** des opérations et il en **sert**.

Servir demande le **backend Temporal**. Les backends in-memory et DBAL n'ont aucune route entre
namespaces, et ils le signalent par une erreur ; voir [Backends](../backends/).

Vous pouvez garder un journal SQL (l'enregistrement, en ajout seul, de tout ce qu'une exécution de
workflow a décidé et reçu) et servir quand même. `durable.backend: dbal` avec un `temporal.dsn`
déclare le cluster joignable pendant que le journal SQL reste la source de vérité. C'est ainsi
qu'une boutique dont le tableau de bord lit DBAL sert une opération Nexus, et ce tableau de bord
continue de lire les mêmes données. Pour appeler, c'est l'inverse : un workflow ordonnance
l'opération, et il ne peut en ordonnancer une que si son journal **est** le cluster.

---

## Appeler une opération

```php
#[AsNexusService('billing')]
interface BillingContract
{
    #[AsNexusOperation('charge')]
    public function charge(string $ordre, int $montant): array;
}
```

```php
$billing = $env->nexusStub(BillingContract::class, endpoint: 'payments');

$recu = $env->await($billing->charge('CMD-42', 1200));
```

Vous écrivez le contrat **une fois**, et les deux côtés de la frontière le lisent : l'appelant en
dérive un stub typé, le gestionnaire l'implémente. Vous ne recopiez jamais un nom d'opération en
chaîne, si bien qu'une faute de frappe devient une erreur de type, au lieu d'une opération qui
attend un gestionnaire dont le nom ne correspond jamais.

L'endpoint est un paramètre du stub, et le contrat ne le mentionne pas. L'endpoint dit *où* le
service est servi. Cela relève du déploiement et change d'un environnement à l'autre ; le contrat,
lui, reste le même.

`nexusStub()` assemble l'appel et `await()` l'attend, comme partout ailleurs dans un workflow ; voir
[Créer un workflow](../workflows/).

La charge voyage **telle que vous l'avez écrite**. Durable ne l'entoure d'aucune enveloppe, donc un
gestionnaire écrit avec le SDK Go, Java ou TypeScript y lit les champs qu'il déclare.

Cela limite aussi ce qu'un contrat peut déclarer. La charge est du JSON simple, clée par nom de
paramètre, et l'autre côté la décode **en tableau associatif**. Un paramètre typé objet y arrive en
tableau, et le gestionnaire lève un `TypeError` au moment de l'appel, et non à l'écriture du
contrat. Les contrats portent donc des scalaires et des tableaux. En PHP, un tableau associatif
**vide** s'encode `[]` et non `{}`, si bien qu'un champ qui peut être vide a besoin d'un champ
voisin qui dise s'il faut le lire.

Que le gestionnaire réponde tout de suite ou dans deux heures, le code appelant reste le même : le
workflow attend l'opération jusqu'à l'arrivée de son résultat.

---

## Servir une opération

Un gestionnaire implémente le contrat, ou la part de celui-ci à laquelle il répond tout de suite :

```php
use Gplanchat\Durable\Attribute\AsNexusServiceHandler;

#[AsNexusServiceHandler(contract: BillingContract::class)]
final class Billing implements BillingServed
{
    public function verify(string $ordre): array
    {
        return $this->regles->controler($ordre);
    }
}
```

L'enregistrement du gestionnaire dépend de l'hôte. Sous Symfony, placez `#[AsNexusServiceHandler]`
sur un service, et le bundle l'autoconfigure. Laravel ne découvre pas les gestionnaires par leur
attribut : il sert les classes listées dans `nexus.handlers` de `config/durable.php`, chacune sous
la forme `gestionnaire => contrat`, ou la classe du gestionnaire seule, dont le
`#[AsNexusServiceHandler]` nomme alors le contrat ([la forme en paire plus bas](#servir-est-du-travail-dhôte-et-ce-nest-pas-du-travail-symfony)).
Magento liste chaque
gestionnaire dans l'argument `nexusHandlers` de `RuntimeFactory` dans `di.xml`, et son
`#[AsNexusServiceHandler]` nomme le contrat. Voir [qui enregistre quoi, par hôte](../getting-started/#déclarer-workflows-et-activités).

### Pourquoi le contrat vient en deux morceaux

Une opération remplie par un workflow n'a pas de corps de gestionnaire. Durable démarre le
workflow, et le serveur en livre le résultat. Le contrat se sépare donc en deux interfaces :
celle qu'un gestionnaire **implémente**, et celle qui l'**étend** pour l'appelant.

```php
#[AsNexusService('billing')]
interface BillingServed                            // répondu tout de suite
{
    #[AsNexusOperation('verify')]
    public function verify(string $ordre): array;
}

#[AsNexusService('billing')]
interface BillingContract extends BillingServed // + ce qu'un workflow remplit
{
    #[AsNexusOperation('charge')]
    public function charge(string $ordre, int $montant): array;
}

#[AsWorkflow('Charge')]
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class Charge { /* … */ }
```

Sans cette séparation, PHP exige un corps pour `charge()` sur le gestionnaire : une méthode vide
qui n'existe que pour montrer qu'il n'y a rien à écrire. Avec la séparation, le workflow réclame
l'opération dans sa propre classe, là où vit son code, et le contrat de l'appelant déclare quand
même chaque opération, pour que le stub puisse toutes les appeler.

### Répondre maintenant, ou répondre plus tard

Un gestionnaire répond sous l'une de deux formes, et le choix entre elles est la décision
principale quand vous servez une opération.

```php
// Maintenant : le gestionnaire rend le type déclaré par le contrat.
public function verify(string $ordre): array { … }

// Plus tard : un workflow réclame l'opération, et produit le résultat.
#[FulfilsNexusOperation(BillingContract::class, 'charge')]
final class Charge { … }
```

**Un gestionnaire dispose d'environ neuf secondes.** Ce délai couvre la réponse à *cette tâche*,
quel que soit le budget de l'opération elle-même. Le `scheduleToClose` de l'appelant peut valoir
cinq minutes, alors que la tâche porte un `request-timeout` d'environ neuf secondes. Quand un
gestionnaire travaille encore à l'expiration, sa tâche est redélivrée et le gestionnaire
recommence. Redélivrances mesurées : ~9,9 s, ~20,7 s, ~33,6 s.

Réservez une méthode implémentée à une lecture, une validation ou un calcul dont vous savez qu'il
est rapide. Tout ce qui parle à un prestataire de paiement, attend un humain ou réessaie pendant une
journée appartient à un workflow, que vous déclarez avec `#[FulfilsNexusOperation]`.

Quand vous nommez un workflow, Durable le démarre avec le callback de l'appelant attaché, et le
serveur livre le résultat de ce workflow à l'appelant quand il se termine. Votre gestionnaire n'est
pas rappelé.

### Annulation

Si l'appelant annule, Durable annule le workflow qui remplit l'opération. Vous n'écrivez aucun
crochet d'annulation : votre workflow observe déjà son annulation et compense, comme décrit dans
[Annulation](../cancellation/).

Une annulation n'atteint un gestionnaire que pour une opération **démarrée**. Pour une opération
qui attend encore sa première réponse, il n'y a rien à annuler de votre côté.

### Faire échouer une opération {#échouer}

Pour faire échouer l'opération, levez une exception :

```php
throw new \RuntimeException('le prestataire de paiement est injoignable');
```

Une exception ordinaire est rapportée en `INTERNAL`, qui est **réessayable** : la tâche revient,
jusqu'au budget de l'opération. Cela convient à une panne. Cela ne convient pas à une requête
invalide, qu'aucun réessai ne corrige. Pour un échec définitif, indiquez sa nature :

| définitif, ne pas réessayer | réessayable, retenter |
|---|---|
| `BAD_REQUEST`, `UNAUTHENTICATED`, `UNAUTHORIZED` | `RESOURCE_EXHAUSTED`, `INTERNAL` |
| `NOT_FOUND`, `NOT_IMPLEMENTED`, `CONFLICT` | `UNAVAILABLE`, `UPSTREAM_TIMEOUT`, `REQUEST_TIMEOUT` |

Les deux colonnes se séparent selon *à qui revient la faute*. Réessayer ne répare pas une requête
malformée ou un droit manquant ; cela peut passer une surcharge ou un délai dépassé en amont. La
table vient de nexus-rpc, et tous les SDK la partagent ; Durable ne l'a pas définie.

Une opération que personne ne sert reçoit `NOT_IMPLEMENTED`, qui est définitif, et le worker
continue de servir ses autres opérations.

---

## Lancer le worker

Servir demande un worker (le processus qui tire le travail et sert les opérations Nexus ; voir le
[glossaire](../glossary/)) sur la file de tâches Nexus. Le bundle l'enregistre, comme les workers de
workflow et d'activité, dès qu'un gestionnaire est déclaré, et vous n'ajoutez rien à
`messenger.yaml` :

```bash
php bin/console messenger:consume durable_nexus --time-limit=3600
```

La file vient du DSN. `nexus_task_queue` la fixe ; **par défaut elle suit la file de workflow**,
parce qu'un endpoint Nexus vise une file et que le serveur ne livre qu'à une file qu'un worker
interroge. Si aucun worker n'interroge la file, l'endpoint ne répond jamais et aucune erreur
n'apparaît nulle part.

---

## Enregistrer l'endpoint

Un endpoint est un objet de tout le cluster. Un opérateur le crée une fois ; l'application ne le
crée pas :

```bash
temporal operator nexus endpoint create \
    --name payments \
    --target-namespace production \
    --target-task-queue durable-workflows
```

Le `--target-task-queue` doit être la file qu'interroge votre worker Nexus.

---

## Si vous déclarez un gestionnaire sur le mauvais backend

La construction du conteneur échoue, et le message nomme ce qui manque :

```
durable.nexus_handler: a Nexus handler is declared, but this backend cannot route
Nexus operations. Nexus needs the Temporal backend — set durable.temporal.dsn.
Declared by: app.charge.
```

Le côté appelant se comporte autrement, et c'est voulu. Un appel sur un backend sans route échoue à
l'appel, et vous l'apprenez tout de suite. Un *gestionnaire* sans route ne reçoit rien, et rien
n'échoue : aucune requête ne lui parvient. Le contrôle a donc lieu au démarrage de l'application.

---

## Une démonstration à quatre applications {#quatre-applications-en-vrai}

Le dépôt embarque une démonstration où quatre applications Durable s'appellent, à travers trois
frameworks.

| | `sylius/`, la boutique | `symfony/`, le métier | `magento/`, le banc Magento | `laravel/`, la logistique |
|---|---|---|---|---|
| namespace | `demo-shop` | `demo-business` | `demo-magento` | `demo-laravel` |
| sert | `stock` (`reserve`) | `billing` (`verify`, `charge`) | **rien** | `delivery` (`schedule`, `ship`) |
| appelle | `billing` | `stock` | les trois services | `stock`, **depuis le workflow qui sert** |
| ce qui déclare le gestionnaire | une balise sous `when@demo` | `#[AsNexusServiceHandler]` | rien | six lignes de `config/durable.php` |

Les quatre lisent le même paquet de contrats. Rien d'autre ne circule entre elles.

Le workflow de commande de la boutique appelle les deux formes sur le même stub :

```php
$verdict = $this->environment->await($this->billing->verify($order, $amount, $currency));

if (true !== ($verdict['accepted'] ?? false)) {
    return ['verified' => $verdict, 'charge' => null];
}

return [
    'verified' => $verdict,
    'charge' => $this->environment->await($this->billing->charge($order, $amount, $currency)),
];
```

`verify` est répondue par une méthode que le métier a écrite. `charge` n'a aucun corps de
gestionnaire : un workflow la réclame, dort douze secondes, appelle une activité de paiement, et son
résultat devient celui de l'opération. **Rien dans le code ci-dessus ne distingue les deux.**
L'historique de l'appelant montre la différence :

```
 5  NexusOperationScheduled     verify
 6  NexusOperationCompleted     verify    ← la même seconde
10  NexusOperationScheduled     charge
11  NexusOperationStarted       charge    ← un workflow l'a prise
15  NexusOperationCompleted     charge    ← quatorze secondes plus tard
19  WorkflowExecutionCompleted
```

Pendant un passage, le worker qui fait avancer le workflow remplissant est resté **éteint quatre
minutes**. L'opération est restée en `NexusOperationStarted`, l'appelant n'a rien consommé, et
tout s'est terminé normalement au retour du worker. Ce passage montre, mesure à l'appui, qu'une
opération en attente ne garde rien d'ouvert.

### Appeler ne demande rien à votre hôte

La troisième application sépare ce que Nexus exige du framework de ce qu'il exige de vous. Les deux
premières sont toutes deux en Symfony : elles partagent son conteneur, la passe de compilation qui
enregistre les gestionnaires et le transport Messenger qui fait tourner les workers. À ne voir que
ces deux-là, tout cela pourrait passer pour une fonctionnalité du bundle.

Le banc Magento n'a rien de tout cela. Il câble ses services en `di.xml`, lance son worker par
`bin/magento durable:worker --role=journal`, et lit son DSN dans `app/etc/env.php`. Il appelle les
trois services, les immédiats et les deux qu'un workflow remplit, **sans aucune modification du
cœur, du pont Temporal ou de `gplanchat/durable-magento`**.

Les deux côtés ne sont pas symétriques :

- **Appeler** demande un workflow dont le journal est la grappe, et rien d'autre.
  `WorkflowEnvironment::nexusStub()` lit le contrat par réflexion ; aucun conteneur n'intervient.
- **Servir** demande à l'hôte d'enregistrer des gestionnaires et d'interroger une file de tâches Nexus.
  C'est du travail d'hôte, écrit une fois par hôte : une passe de compilation en Symfony, un fichier
  de configuration en Laravel, un argument de `di.xml` en Magento.

La grappe montre cette asymétrie : quatre namespaces, **trois endpoints**. Un endpoint dit où
un service est servi, donc une application qui ne fait qu'appeler n'en a pas.

```php
// Le banc Magento, appelant trois services depuis un seul workflow. C'est tout ce que
// l'intégration à l'hôte représente : trois stubs et cinq opérations attendues.
$verdict = $this->environment->await($this->billing->verify($order, $amount, $currency));
$delivery = $this->environment->await($this->delivery->schedule($order, $lines));
$reservation = $this->environment->await($this->stock->reserve($order, $lines));
$receipt = $this->environment->await($this->billing->charge($order, $amount, $currency));
$shipment = $this->environment->await($this->delivery->ship($order, $delivery['slot']));
```

> [!WARNING]
> **L'ordre de ces cinq appels compte.** Deux ordres inversés ont été écrits d'abord, et tous deux
> mesurés : une commande en USD retenait le stock **puis** se faisait refuser sa facture, et une
> commande de six colis était **encaissée** avant que la logistique ne refuse de la porter. Aucun
> des trois contrats n'a d'opération qui rende ce qu'il a pris. **Appelez d'abord toutes les
> opérations qui peuvent dire non, et n'engagez qu'ensuite.** Quand une opération n'a pas de
> contrepartie compensatoire, l'ordre des appels tient lieu de compensation.

### Servir sur un autre hôte que Symfony {#servir-est-du-travail-dhôte-et-ce-nest-pas-du-travail-symfony}

L'autre moitié de l'asymétrie a sa propre démonstration. Avant le banc Laravel, toute opération
servie était enregistrée par une passe de compilation Symfony et interrogée par un transport
Messenger. Voici **tout** le câblage d'hôte, sur un framework qui n'a ni l'une ni l'autre :

```php
// config/durable.php
'backend' => env('DURABLE_BACKEND', 'temporal'),   // servir du Nexus exige la grappe : c'est elle qui route
'temporal' => ['dsn' => env('DURABLE_DSN')],
'workflows' => [App\Durable\Workflow\ShipWorkflow::class],
'nexus' => ['handlers' => [
    App\Durable\Nexus\DeliveryHandler::class => DeliveryContract::class,
]],
```

`DeclaredNexusOperations` lit ce fichier comme `NexusHandlerPass` lit les balises de Symfony, par le
même `NexusContractResolver` et le même `NexusHandlerInvoker` ; `php artisan durable:nexus-worker`
interroge la file. La classe du gestionnaire ne contient rien de tout cela : elle implémente
`DeliveryServed` et ne mentionne pas Nexus.

> [!WARNING]
> **Le contrôle des signatures vit au cœur, partagé par les deux hôtes.** L'enregistrement échoue
> pour un workflow remplissant dont un paramètre obligatoire ne correspond à rien dans la signature
> du contrat, et le message nomme les deux signatures. La charge est clée par nom de paramètre aux
> deux bouts : sans ce contrôle, le paramètre recevrait `null`. Symfony appelle le contrôle depuis
> sa passe de compilation, Laravel depuis `durable.nexus.handlers`. Il a été écrit pour le premier
> hôte et a rejoint le cœur quand un second hôte est arrivé.

### Un workflow qui sert peut appeler

`ShipWorkflow` remplit `delivery/ship`. Avant de sortir la marchandise, il redemande son
verdict à la boutique par `stock/reserve`, sur l'endpoint d'une autre application. Une même
exécution (un déroulement durable d'un workflow ; voir le [glossaire](../glossary/)) porte donc une
opération qu'elle sert et une opération qu'elle appelle :

```
 5  TimerStarted              ← les six secondes de préparation
 6  TimerFired
10  NexusOperationScheduled   ← stock/reserve, chez la boutique
11  NexusOperationCompleted
15  WorkflowExecutionCompleted
```

Son identifiant d'exécution est le **jeton de l'opération** qu'elle remplit : un workflow démarré par
une tâche Nexus n'est pas nommé par l'application qui l'exécute.

L'appel est sans risque parce que `reserve` est idempotente par identifiant de commande : la
boutique relit la décision prise à la commande au lieu d'en prendre une nouvelle, c'est pourquoi les
lignes passées sont vides.

Les prérequis, les processus à démarrer et les commandes à lancer sont dans
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md). Avant de
commencer, notez deux points. Un serveur qui répond `Nexus APIs are disabled` ne convient pas,
`temporal server start-dev` convient. Les quatre applications ne tournent pas sur le même binaire PHP.

---

## Voir aussi

- [Backends](../backends/) dit quel backend peut router Nexus, et pourquoi les autres ne le peuvent pas.
- [Annulation](../cancellation/) couvre ce que fait votre workflow quand l'appelant annule.
- [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md) porte la décision, et les mesures derrière.
