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

Cela n'oblige pas à renoncer à un journal SQL. `durable.temporal.journal: false` dit que le cluster
est joignable pendant qu'`event_store` reste la source de vérité — c'est ainsi qu'une boutique dont
le tableau de bord lit DBAL sert une opération Nexus sans que ce tableau de bord change ce qu'il
lit. Appeler est l'inverse : une opération est ordonnancée par un workflow, et un workflow ne peut
en ordonnancer une que si son journal **est** le cluster.

---

## Appeler une opération

```php
#[AsNexusService('facturation')]
interface FacturationContract
{
    #[AsNexusOperation('encaisser')]
    public function encaisser(string $ordre, int $montant): array;
}
```

```php
$facturation = $env->nexusStub(FacturationContract::class, endpoint: 'paiements');

$recu = $env->await($facturation->encaisser('CMD-42', 1200));
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

C'est aussi ce qui contraint ce qu'un contrat peut déclarer. La charge est du JSON simple, clée par
nom de paramètre, et elle est décodée **en tableau associatif** de l'autre côté. Un paramètre typé
objet y arriverait en tableau, et le gestionnaire lèverait un `TypeError` au moment de l'appel —
pas à l'écriture du contrat. Les contrats portent donc des scalaires et des tableaux. Un détail PHP
mérite d'être connu : un tableau associatif **vide** s'encode `[]` et non `{}`, si bien qu'un champ
qui peut être vide a besoin d'un champ voisin qui dise s'il faut le lire.

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
    public function verifier(string $ordre): array
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
    public function verifier(string $ordre): array;
}

#[AsNexusService('facturation')]
interface FacturationContract extends FacturationServie // + ce qu'un workflow remplit
{
    #[AsNexusOperation('encaisser')]
    public function encaisser(string $ordre, int $montant): array;
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
public function verifier(string $ordre): array { … }

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

## Quatre applications, en vrai

Le dépôt embarque une démonstration où quatre applications Durable s'appellent, à travers trois
frameworks. Ce qu'elle montre se lit mieux qu'il ne se décrit.

| | `sylius/` — la boutique | `symfony/` — le métier | `magento/` — le banc Magento | `laravel/` — la logistique |
|---|---|---|---|---|
| namespace | `demo-boutique` | `demo-metier` | `demo-magento` | `demo-laravel` |
| sert | `stock` (`reserver`) | `facturation` (`verifier`, `encaisser`) | **rien** | `livraison` (`planifier`, `expedier`) |
| appelle | `facturation` | `stock` | les trois services | `stock`, **depuis le workflow qui sert** |
| ce qui déclare le gestionnaire | une balise sous `when@demo` | `#[AsNexusServiceHandler]` | — | six lignes de `config/durable.php` |

Les quatre lisent le même paquet de contrats. Rien d'autre ne circule entre elles.

Le workflow de commande de la boutique appelle les deux formes sur le même stub :

```php
$verdict = $this->environment->await($this->facturation->verifier($commande, $montant, $devise));

if (true !== ($verdict['acceptee'] ?? false)) {
    return ['verifiee' => $verdict, 'encaissement' => null];
}

return [
    'verifiee' => $verdict,
    'encaissement' => $this->environment->await($this->facturation->encaisser($commande, $montant, $devise)),
];
```

`verifier` est répondue par une méthode que le métier a écrite. `encaisser` n'a aucun corps de
gestionnaire : un workflow la réclame, dort douze secondes, appelle une activité de paiement, et son
résultat devient celui de l'opération. **Rien dans le code ci-dessus ne distingue les deux.**
L'historique de l'appelant, si :

```
 5  NexusOperationScheduled     verifier
 6  NexusOperationCompleted     verifier      ← la même seconde
10  NexusOperationScheduled     encaisser
11  NexusOperationStarted       encaisser     ← un workflow l'a prise
15  NexusOperationCompleted     encaisser     ← quatorze secondes plus tard
19  WorkflowExecutionCompleted
```

Pendant un passage, le worker qui devait faire avancer le workflow remplissant est resté **éteint
quatre minutes**. L'opération est restée en `NexusOperationStarted`, l'appelant n'a rien consommé,
et tout s'est terminé normalement au retour du worker. C'est cela, « l'attente ne tient rien
d'ouvert » — et ce n'est pas une chose qu'un schéma permet d'affirmer.

### Appeler ne demande rien à votre hôte

La troisième application est là pour séparer ce que Nexus demande au framework de ce qu'il vous
demande à vous. Les deux premières sont toutes deux en Symfony : elles partagent son conteneur, la
passe de compilation qui enregistre les gestionnaires et le transport Messenger qui tourne les
workers. On pouvait raisonnablement lire tout cela comme une fonctionnalité du bundle.

Le banc Magento n'a rien de tout ça. Il câble ses services en `di.xml`, tourne son worker par
`bin/magento durable:worker --role=journal`, et lit son DSN dans `app/etc/env.php`. Il appelle les
trois services — les immédiats et les deux qu'un workflow remplit — et **pas une ligne n'a été
ajoutée au cœur, au pont Temporal ou à `gplanchat/durable-magento`** pour cela.

La raison est que les deux côtés ne sont pas symétriques :

- **Appeler** demande un workflow dont le journal est la grappe, et rien d'autre.
  `WorkflowEnvironment::nexusStub()` lit le contrat par réflexion ; aucun conteneur n'intervient.
- **Servir** demande à l'hôte d'enregistrer des gestionnaires et de poller une file de tâches Nexus.
  C'est du travail d'hôte, écrit une fois par hôte — une passe de compilation en Symfony, un fichier
  de configuration en Laravel, et, pour l'instant, rien en Magento.

Cette asymétrie se voit dans la grappe : quatre namespaces, **trois endpoints**. Un endpoint dit où
un service est servi, donc une application qui ne fait qu'appeler n'en a pas.

```php
// Le banc Magento, appelant trois services depuis un seul workflow. C'est tout ce que
// l'intégration à l'hôte représente : trois stubs et cinq opérations attendues.
$verdict = $this->environment->await($this->facturation->verifier($commande, $montant, $devise));
$livraison = $this->environment->await($this->livraison->planifier($commande, $lignes));
$reservation = $this->environment->await($this->stock->reserver($commande, $lignes));
$recu = $this->environment->await($this->facturation->encaisser($commande, $montant, $devise));
$suivi = $this->environment->await($this->livraison->expedier($commande, $livraison['creneau']));
```

⚠ **L'ordre de ces cinq appels n'est pas cosmétique.** Deux inversions ont été écrites d'abord, et
toutes deux mesurées : une commande en USD retenait le stock **puis** se faisait refuser sa facture,
et une commande de six colis était **encaissée** avant que la logistique ne refuse de la porter.
Aucun des trois contrats n'a d'opération qui rende ce qu'il a pris. **Demander d'abord tout ce qui
peut dire non, n'engager qu'ensuite** — quand une opération n'a pas de contrepartie compensatoire,
l'ordre des appels **est** la compensation.

### Servir est du travail d'hôte, et ce n'est pas du travail Symfony

L'autre moitié de l'asymétrie a sa propre démonstration, parce que jusqu'à la maquette Laravel toute
opération servie l'avait été par une passe de compilation Symfony et pollée par un transport
Messenger. Voici **tout** le câblage d'hôte, sur un framework qui n'a ni l'une ni l'autre :

```php
// config/durable.php
'backend' => env('DURABLE_BACKEND', 'temporal'),   // servir du Nexus exige la grappe : c'est elle qui route
'temporal' => ['dsn' => env('DURABLE_DSN')],
'workflows' => [App\Durable\Workflow\ExpedierWorkflow::class],
'nexus' => ['handlers' => [
    App\Durable\Nexus\LivraisonHandler::class => LivraisonContract::class,
]],
```

`DeclaredNexusOperations` lit ce fichier comme `NexusHandlerPass` lit les balises de Symfony, par le
même `NexusContractResolver` et le même `NexusHandlerInvoker` ; `php artisan durable:nexus-worker`
poll la file. La classe du gestionnaire, elle, n'en sait rien — elle implémente `LivraisonServed` et
ne dit pas un mot de Nexus.

⚠ **Le contrôle qui tient cela honnête vit au cœur, et non chez l'un des deux hôtes.** Un workflow
remplissant dont un paramètre obligatoire ne correspond à rien dans la signature du contrat est
refusé à l'enregistrement, et le message nomme les deux signatures — la charge étant clée par nom
aux deux bouts, sans ce refus le paramètre recevrait simplement `null`. Symfony appelle le contrôle
depuis sa passe de compilation, Laravel depuis `durable.nexus.handlers` ; il a été écrit pour le
premier hôte et a déménagé le jour où il en a eu un second.

### Un workflow qui sert peut appeler

`ExpedierWorkflow` remplit `livraison/expedier`. Avant de sortir la marchandise, il redemande son
verdict à la boutique par `stock/reserver`, sur un endpoint qui n'est pas le sien — une même
exécution porte donc une opération qu'elle sert et une opération qu'elle appelle :

```
 5  TimerStarted              ← les six secondes de préparation
 6  TimerFired
10  NexusOperationScheduled   ← stock/reserver, chez la boutique
11  NexusOperationCompleted
15  WorkflowExecutionCompleted
```

Son identifiant d'exécution est le **jeton de l'opération** qu'elle remplit : un workflow démarré par
une tâche Nexus n'est pas nommé par l'application qui l'exécute.

L'appel est sans risque parce que `reserver` est idempotente par identifiant de commande : la
boutique relit la décision prise à la commande au lieu d'en prendre une nouvelle — c'est pourquoi les
lignes passées sont vides.

Les prérequis, les processus à démarrer et les commandes à lancer sont dans
[`demo/README.md`](https://github.com/gplanchat/durable-dev/blob/main/demo/README.md). Deux choses à
savoir avant de commencer : un serveur qui répond `Nexus APIs are disabled` ne convient pas —
`temporal server start-dev`, si — et les quatre maquettes ne tournent pas sur le même binaire PHP.

---

## Voir aussi

- [Backends](../backends/) — quel backend sait router Nexus, et pourquoi les autres refusent.
- [Annulation](../cancellation/) — ce que fait votre workflow quand l'appelant annule.
- [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md) — la décision, et les mesures derrière.
