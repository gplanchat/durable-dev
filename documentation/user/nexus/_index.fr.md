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
$result = $env->await($env->nexusOperation(
    endpoint: 'paiements',
    service: 'facturation',
    operation: 'encaisser',
    payload: ['amount' => 1200, 'currency' => 'EUR'],
    timeouts: new NexusOperationTimeouts(scheduleToClose: Duration::minutes(5)),
));
```

`nexusOperation()` assemble ; `await()` attend. C'est la règle partout ailleurs — voir
[Créer un workflow](../workflows/).

La charge voyage **telle que vous l'avez écrite**. Aucune enveloppe Durable ne l'entoure, donc un
gestionnaire écrit avec le SDK Go, Java ou TypeScript y lit les champs qu'il déclare.

Que le gestionnaire réponde tout de suite ou dans deux heures ne change rien ici : le workflow
attend l'opération, et le résultat arrive quand il arrive.

---

## Servir une opération

Déclarez le service et l'opération sur un gestionnaire :

```php
use Gplanchat\Durable\Bundle\Attribute\AsNexusOperationHandler;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;

#[AsNexusOperationHandler(service: 'facturation', operation: 'encaisser')]
final class Encaisser
{
    public function __invoke(mixed $payload): NexusOperationResponse
    {
        return NexusOperationResponse::completed(['receipt' => 'r-1234']);
    }
}
```

Le couple `(service, opération)` est toute l'adresse : une tâche entrante est routée par lui et par
rien d'autre. Les deux noms sont vérifiés au montage du conteneur, parce qu'une faute de frappe
donne un gestionnaire que rien n'atteint jamais — et le serveur n'a rien à en dire.

### Répondre maintenant, ou répondre plus tard

`NexusOperationResponse` a deux formes, et choisir entre elles est la seule décision qui compte.

```php
// Maintenant — vous avez la réponse.
return NexusOperationResponse::completed(['receipt' => 'r-1234']);

// Plus tard — un workflow la produira.
return NexusOperationResponse::fulfilledByWorkflow('Encaissement', $payload);
```

**Un gestionnaire dispose d'environ neuf secondes.** Ce n'est pas le budget de l'opération, c'est
celui pour répondre à *cette tâche* : le `scheduleToClose` de l'appelant peut valoir cinq minutes,
la tâche elle-même porte un `request-timeout` d'environ neuf secondes. Un gestionnaire encore au
travail quand il expire voit sa tâche redélivrée — et recommence. Redélivrances mesurées : ~9,9 s,
~20,7 s, ~33,6 s.

`completed()` est donc pour une lecture, une validation, un calcul dont vous savez qu'il est
rapide. Tout ce qui parle à un prestataire de paiement, attend un humain ou réessaie pendant une
journée appartient à un workflow — et c'est à quoi sert `fulfilledByWorkflow()`.

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

## Voir aussi

- [Backends](../backends/) — quel backend sait router Nexus, et pourquoi les autres refusent.
- [Annulation](../cancellation/) — ce que fait votre workflow quand l'appelant annule.
- [DUR045](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR045-serving-a-nexus-operation.md) — la décision, et les mesures derrière.
