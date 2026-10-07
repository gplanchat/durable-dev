---
title: Annulation
weight: 27
---

# Annulation

Quand vous annulez une exécution (un déroulement durable d'un workflow ; voir le
[glossaire](../glossary/)), Durable ne la tue pas. L'annulation est **levée à l'intérieur du workflow, à l'endroit
où il attend**, pour qu'il puisse compenser avant de se terminer. C'est l'équivalent du
`CanceledFailure` de Temporal.

---

## Compenser

Pour défaire les étapes terminées avant un échec ou une annulation, enregistrez une compensation
par étape avec `Saga`, et exécutez-les depuis un `catch` :

```php
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Workflow\Saga;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'checkout')]
final class CheckoutWorkflow
{
    /** @param ActivityStub<OrderActivities> $orders */
    #[AsWorkflowMethod]
    public function run(
        string $orderId,
        #[Activities(OrderActivities::class)]
        ActivityStub $orders,
        WorkflowEnvironment $env,
    ): string {
        $saga = new Saga();

        try {
            $reservation = $env->await($orders->reserve($orderId));
            $saga->addCompensation(fn () => $env->await($orders->release($reservation)));

            $charge = $env->await($orders->charge($orderId));
            $saga->addCompensation(fn () => $env->await($orders->refund($charge)));

            return $env->await($orders->ship($orderId));
        } catch (DurableActivityFailedException|WorkflowCancelledFailure $e) {
            $saga->compensate();

            throw $e;   // l'exécution se termine annulée, ou en échec
        }
    }
}
```

`Saga` enregistre une compensation par étape terminée et, à l'appel de `compensate()`, les exécute
dans l'ordre inverse. Chaque compensation fait son propre `await()`, si bien qu'elle se termine avant
que la suivante ne commence. Si une compensation renvoie un `Awaitable` à la place, `compensate()` lève une
`LogicException`. Une étape qui ne s'est jamais terminée n'a rien à défaire,
puisque sa compensation n'a jamais été ajoutée. La première compensation qui lève une exception
arrête la série, et son exception remplace celle en cours de compensation.

La fin de l'exécution dépend de ce que le workflow fait de l'exception. Chacun de ces dénouements
est légitime :

| Le workflow… | Dénouement |
|---|---|
| relance l'échec | l'exécution se termine **annulée** |
| l'avale et rend une valeur | l'exécution **se termine** normalement ; un workflow a le droit d'ignorer l'annulation |
| n'attend jamais rien | l'annulation n'est jamais observée et le workflow se termine |

L'opération attendue est annulée en même temps. Dans une course, toutes les branches en attente
sont annulées.

---

## Livrée exactement une fois

L'annulation est levée **une fois par exécution**. Si elle était levée de nouveau, les attentes
dont se sert la compensation seraient annulées à leur tour, et la compensation n'aurait jamais lieu.

Le rejeu (la réexécution du code du workflow depuis sa première ligne, où chaque étape enregistrée
renvoie son résultat) reste déterministe parce que le dénouement figure dans le journal (l'historique, en ajout seul, de ce que l'exécution
a décidé et reçu), sans marqueur à part. L'opération en attente est annulée avec la raison
`workflow_cancelled`, et au rejeu ce dénouement enregistré rejette le même awaitable au même endroit. Le workflow prend donc la même branche à
chaque rejeu.

---

## Demander une annulation

- **Depuis un parent.** Quand le parent se ferme, un enfant planifié avec
  `ParentClosePolicy::RequestCancel` reçoit une demande d'annulation.
- **De l'extérieur, sur Temporal.** Lancez `temporal workflow cancel`, ou appelez
  `RequestCancelWorkflowExecution` depuis n'importe quel client. Le serveur enregistre la demande et
  replanifie une tâche de workflow, que le worker traite ensuite.
- **Depuis votre application, sur Temporal.** Appelez `WorkflowClient::cancel($workflowId)` pour
  demander l'annulation, ou `WorkflowClient::terminate($workflowId, $reason)` pour terminer
  l'exécution aussitôt, sans exécuter une ligne de plus du workflow. Les deux prennent l'identifiant
  que renvoie `WorkflowClient::workflowId()`. Sur une exécution terminée, ou qui n'existe pas, le
  serveur répond NotFound et les deux méthodes lèvent une `\RuntimeException` de code 5, qui nomme
  l'identifiant et garde l'échec du serveur comme exception précédente. Rien n'est modifié. Terminer
  deux fois une exécution lève donc une exception au second appel. Ces méthodes n'existent que sur
  Temporal. Les backends à journal (InMemory, DBAL, Illuminate, Magento) ne les ont pas encore.
- **De l'extérieur, sur les autres backends.** L'application n'a aucun point d'entrée pour demander
  une annulation en mémoire, sur DBAL, sur Illuminate ou sur Magento ; la
  [matrice de capacités](../backends/#capability-matrix) classe la ligne comme non prise en charge
  sur les trois premiers et « pas encore » pour la colonne Magento Database.

---

## Ce qu'elle laisse dans le journal

| Événement | Sens |
|---|---|
| `WorkflowCancellationRequested` | une annulation a été demandée |
| `WorkflowExecutionCancelled` | l'exécution s'est terminée annulée |
| `ActivityCancelled` / `TimerCancelled` avec la raison `workflow_cancelled` | l'opération attendue a été retirée |

Un perdant de course est annulé avec la raison `race_superseded` à la place, et remonte en
`ActivitySupersededException`. Les deux situations restent distinguables.

---

## Les perdants d'une course

```php
$winner = $this->environment->await(
    $this->environment->any(
        $this->quotes->callProvider($orderId),
        $this->quotes->callFallbackProvider($orderId),
    ),
    deadline: Duration::seconds(30),
);
```

Quand une branche gagne, les autres sont annulées. Leurs activités en attente sont retirées de la
file, et leurs minuteurs en attente ne réveillent plus l'exécution. Une échéance écoulée les annule
de la même façon, et lève `DeadlineExceededException`.

**La borne de temps est l'échéance passée à `await()`, pas une troisième branche.** Un minuteur mis
en course avec les fournisseurs aurait l'air d'un gagnant. `any()` se résout à la *valeur*
gagnante et à rien d'autre, si bien qu'un fournisseur qui répond légitimement `null` devient
indiscernable de trente secondes de silence, et le chemin de compensation prévu pour le
dépassement s'exécute aussi sur la réponse vide.

`timer()` renvoie bien un `Awaitable`, exactement comme un appel de stub : il *peut* donc être une
branche. Employez-le comme branche quand le minuteur est un vrai dénouement, par exemple envoyer
une relance ou prendre le chemin de repli. Ne l'employez pas comme échéance. Quand vous voulez
seulement attendre, appelez `sleep()`, qui fait l'attente pour vous.

[Écrire un workflow](../workflows/#bounding-a-wait-in-time) détaille l'échéance, avec ce que porte
l'exception, `deadline()` et `awaited()`.
