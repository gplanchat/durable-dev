<?php

declare(strict_types=1);

namespace App\Ai\Live;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Envoyer un signal à une exécution d'agent, et prévenir les pages ouvertes.
 *
 * Un signal n'a pas le même chemin selon qui détient le journal : sur Temporal natif le cluster
 * **est** le journal, et le signal doit passer par le client ; sur le backend journal il passe par
 * le bus. Ce choix vivait dans le contrôleur ; il a un second appelant depuis que les événements
 * métier réveillent des veilles, et deux copies auraient divergé au premier changement.
 *
 * La sonnerie est indissociable du signal, pas une politesse en plus : un réveil déclenché du
 * dehors — c'est exactement le cas d'usage de {@see AgentLiveFeed} — n'apparaîtrait dans les
 * onglets ouverts qu'au sondage suivant.
 */
final readonly class AgentSignals
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private AgentLiveFeed $feed,
        private ?WorkflowClientInterface $workflowClient = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(string $executionId, string $signalName, array $payload): void
    {
        if (null !== $this->workflowClient) {
            $this->workflowClient->signal($this->workflowClient->workflowId($executionId), $signalName, $payload);
        } else {
            $this->messageBus->dispatch(new DeliverWorkflowSignalMessage($executionId, $signalName, $payload));
        }

        $this->feed->nudge($executionId);
    }
}
