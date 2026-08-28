<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use App\Durable\Activity\EncaissementActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Demo\Contracts\Facturation\FacturationContract;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Ce qui remplit `facturation/encaisser`.
 *
 * Il n'y a pas de méthode de gestionnaire pour cette opération, et c'est le sujet : la plomberie
 * démarre ce workflow avec le callback de la tâche attaché, et le serveur livre son résultat à
 * l'appelant quand il se termine. Le gestionnaire de `facturation` n'est pas rappelé.
 *
 * ⚠ **Les noms de paramètres sont l'interface.** `commande`, `montant` et `devise` sont ceux que
 * `FacturationContract::encaisser()` déclare, et la charge est clée par nom des deux côtés. En
 * renommer un ici sans le renommer là-bas donnerait `null`, sans erreur et sans trace — c'est
 * pourquoi `NexusFulfilmentParameterNamesTest` compare les deux listes.
 */
#[AsWorkflow(self::TYPE)]
#[FulfilsNexusOperation(FacturationContract::class, 'encaisser')]
final class EncaissementWorkflow
{
    public const TYPE = 'EncaissementWorkflow';

    private readonly ActivityStub $paiement;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->paiement = $environment->activityStub(
            EncaissementActivityInterface::class,
            new ActivityOptions(RetryLimit::ofAttempts(3)),
        );
    }

    /**
     * @return array{recu: string, encaisse: int}
     */
    #[AsWorkflowMethod]
    public function run(string $commande, int $montant, string $devise): array
    {
        // `sleep()` et non `timer()` : le second **rend** un awaitable, à attendre ou à composer
        // avec `any()`. L'appeler sans l'attendre démarre un minuteur que rien ne regarde, et le
        // workflow enchaîne — un `TimerStarted` sans `TimerFired` dans l'historique.
        //
        // Le délai est là pour que l'attente dépasse largement les ~9 s d'une tâche Nexus : une
        // opération qui rendrait sa réponse dans ce budget n'aurait pas besoin d'un workflow, et la
        // démonstration ne montrerait rien.
        $this->environment->sleep(12.0);

        return $this->environment->await($this->paiement->encaisser($commande, $montant, $devise));
    }
}
