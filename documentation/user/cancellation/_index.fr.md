---
title: Annulation
weight: 27
---

# Annulation

Annuler une exécution ne la tue pas. L'annulation est **levée à l'intérieur du workflow, à l'endroit
où il attend**, pour qu'il puisse compenser avant de se terminer — l'équivalent du
`CanceledFailure` de Temporal.

---

## Compenser

```php
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'checkout')]
final class CheckoutWorkflow
{
    /** @var ActivityStub<OrderActivities> */
    private readonly ActivityStub $orders;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->orders = $environment->activityStub(OrderActivities::class);
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): string
    {
        try {
            return $this->environment->await($this->orders->charge($orderId));
        } catch (WorkflowCancelledFailure $e) {
            $this->environment->await($this->orders->refund($orderId));

            throw $e;   // l'exécution se termine annulée
        }
    }
}
```

Trois dénouements, tous légitimes :

| Le workflow… | Dénouement |
|---|---|
| relance l'échec | l'exécution se termine **annulée** |
| l'avale et rend une valeur | l'exécution **se termine** normalement — un workflow a le droit d'ignorer l'annulation |
| n'attend jamais rien | l'annulation n'est jamais observée et le workflow se termine |

L'opération en cours d'attente est annulée en même temps. Dans une course, toutes les branches en
attente le sont.

---

## Livrée exactement une fois

L'annulation est levée **une fois par exécution**. Sans cette borne, les attentes servant justement
à compenser seraient annulées à leur tour et la compensation n'aurait jamais lieu.

Le déterminisme vient du journal plutôt que d'un marqueur : l'opération en attente est annulée avec
la raison `workflow_cancelled`, et au rejeu ce dénouement enregistré rejette le même awaitable au
même endroit. Le workflow prend donc la même branche à chaque rejeu.

---

## Demander une annulation

- **Depuis un parent** — un enfant planifié avec `ParentClosePolicy::RequestCancel` se voit demander
  de s'annuler quand le parent se ferme.
- **De l'extérieur, sur Temporal** — `temporal workflow cancel`, ou tout client appelant
  `RequestCancelWorkflowExecution`. Le serveur enregistre la demande et replanifie une tâche de
  workflow ; le worker y répond.

---

## Ce qu'elle laisse dans le journal

| Événement | Sens |
|---|---|
| `WorkflowCancellationRequested` | quelqu'un a demandé |
| `WorkflowExecutionCancelled` | l'exécution s'est terminée annulée |
| `ActivityCancelled` / `TimerCancelled` avec la raison `workflow_cancelled` | l'opération attendue a été retirée |

Un perdant de course est annulé avec la raison `race_superseded` à la place, et remonte en
`ActivitySupersededException` — une autre situation, qui reste distinguable.

---

## Les perdants d'une course

```php
$winner = $this->environment->await(
    $this->environment->any(
        $this->quotes->callProvider($orderId),
        $this->quotes->callFallbackProvider($orderId),
    ),
    Duration::seconds(30),
);
```

Quand une branche gagne, les autres sont annulées : les activités en attente sont retirées de la
file et les minuteurs en attente cessent de pouvoir réveiller l'exécution. Une échéance écoulée les
annule de la même façon, et lève `DeadlineExceededException`.

**La borne de temps est l'échéance passée à `await()`, pas une troisième branche.** Un minuteur mis
en course avec les fournisseurs aurait l'air d'un gagnant : `any()` se résout à la *valeur*
gagnante et à rien d'autre, si bien qu'un fournisseur répondant légitimement `null` devient
indistinguable de trente secondes de silence — et le chemin de compensation prévu pour le
dépassement s'exécuterait aussi sur la réponse vide.

`timer()` renvoie bien un `Awaitable`, exactement comme un appel de stub : il *peut* donc être une
branche — mettez-l'y quand le minuteur est un vrai dénouement (envoyer une relance, prendre le
chemin de repli), jamais quand c'est une échéance déguisée. Quand vous voulez seulement attendre,
`sleep()` le dit dans son nom et fait l'attente pour vous.

Voir [Écrire un workflow](../workflows/#bounding-a-wait-in-time), où l'échéance est détaillée avec
ce que porte l'exception — `deadline()` et `awaited()`.
