<?php

declare(strict_types=1);

namespace App\Durable;

use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Vide les transports workflow / activités jusqu’à {@see ExecutionCompleted}.
 * Les réveils minuteur sont planifiés par le bundle ({@see \Gplanchat\Durable\Bundle\Handler\WorkflowRunHandler}),
 * pas par une boucle qui spamme {@see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage}.
 */
final class DurableMessengerDrain
{
    /** @var list<string> */
    private const DRAIN_TRANSPORTS = ['durable_workflows', 'durable_activities'];

    /** Temps réel max (secondes) : les workflows avec {@see WorkflowEnvironment::delay} repose sur des messages Messenger différés. */
    private const MAX_DRAIN_SECONDS = 120.0;

    /**
     * @return bool true si un {@see ExecutionCompleted} est présent pour cet executionId
     */
    public static function drainUntilWorkflowSettled(
        EventStoreInterface $eventStore,
        WorkflowMetadataStore $workflowMetadataStore,
        MessageBusInterface $messageBus,
        ContainerInterface $receiverLocator,
        string $executionId,
    ): bool {
        $hadMessage = false;
        $idleStreak = 0;
        $t0 = microtime(true);

        while (microtime(true) - $t0 < self::MAX_DRAIN_SECONDS) {
            $worked = false;
            foreach (self::DRAIN_TRANSPORTS as $transportName) {
                $receiver = $receiverLocator->get($transportName);
                foreach ($receiver->get() as $envelope) {
                    $messageBus->dispatch($envelope->with(new ReceivedStamp($transportName)));
                    $hadMessage = true;
                    $worked = true;
                }
            }

            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return true;
            }

            if ($worked) {
                $idleStreak = 0;

                continue;
            }

            ++$idleStreak;
            if ($hadMessage && $idleStreak > 30 && !$workflowMetadataStore->hasActiveWorkflowMetadata($executionId)) {
                return false;
            }

            // Métadonnées actives = workflow encore suspendu (timer, activité asynchrone, etc.) : attendre
            // que la file Doctrine libère les messages différés (DelayStamp) ou les reprises.
            if ($workflowMetadataStore->hasActiveWorkflowMetadata($executionId)) {
                usleep(100_000);
            } else {
                usleep(1_000);
            }
        }

        return false;
    }
}
