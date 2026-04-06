<?php

declare(strict_types=1);

namespace App\Durable;

use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Vide les transports workflow / activités jusqu’à {@see ExecutionCompleted}.
 * Les réveils minuteur sont planifiés par le bundle ({@see \Gplanchat\Durable\Bundle\Handler\ResumeWorkflowHandler}),
 * pas par une boucle qui spamme {@see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage}.
 *
 * Chaque enveloppe retournée par {@see TransportInterface::get()} doit être {@see TransportInterface::ack() ackée}
 * (comportement du worker Symfony) — sinon les transports in-memory renverraient le même message à chaque tour.
 *
 * Avec le **backend Temporal natif** ({@see WorkflowTaskRunner} + {@see TemporalHistoryCursor}), les activités
 * passent par {@code durable_temporal_activity} (poll gRPC) : il faut aussi consommer {@code durable_temporal_journal}
 * et {@code durable_temporal_activity}, comme {@code messenger:consume}.
 */
final class DurableMessengerDrain
{
    /** @var list<string> */
    private const CORE_TRANSPORTS = ['durable_workflows', 'durable_activities'];

    /** @var list<string> */
    private const TEMPORAL_MIRROR_TRANSPORTS = ['durable_temporal_journal', 'durable_temporal_activity'];

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
            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return true;
            }

            $worked = self::drainCoreTransports(
                $receiverLocator,
                $messageBus,
                $hadMessage,
            );

            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return true;
            }

            self::pollTemporalMirrorTransportsIfRegistered($receiverLocator, $eventStore, $executionId);

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

            if ($workflowMetadataStore->hasActiveWorkflowMetadata($executionId)) {
                usleep(100_000);
            } else {
                usleep(1_000);
            }
        }

        return false;
    }

    /**
     * @return 'complete'|'signal_wait'|'timeout'
     */
    public static function drainUntilCompleteOrSignalWait(
        EventStoreInterface $eventStore,
        WorkflowMetadataStore $workflowMetadataStore,
        MessageBusInterface $messageBus,
        ContainerInterface $receiverLocator,
        string $executionId,
    ): string {
        $hadMessage = false;
        $idleStreak = 0;
        $t0 = microtime(true);

        while (microtime(true) - $t0 < self::MAX_DRAIN_SECONDS) {
            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return 'complete';
            }

            $worked = self::drainCoreTransports(
                $receiverLocator,
                $messageBus,
                $hadMessage,
            );

            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return 'complete';
            }

            self::pollTemporalMirrorTransportsIfRegistered($receiverLocator, $eventStore, $executionId);

            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return 'complete';
            }

            if ($worked) {
                $idleStreak = 0;

                continue;
            }

            ++$idleStreak;
            if ($hadMessage && $idleStreak > 30 && !$workflowMetadataStore->hasActiveWorkflowMetadata($executionId)) {
                return 'timeout';
            }

            if ($workflowMetadataStore->hasActiveWorkflowMetadata($executionId)
                && null === WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)
                && $idleStreak >= 5
                && !WorkflowQueryEvaluator::hasPendingTimer($eventStore, $executionId)
            ) {
                return 'signal_wait';
            }

            if ($workflowMetadataStore->hasActiveWorkflowMetadata($executionId)) {
                usleep(100_000);
            } else {
                usleep(1_000);
            }
        }

        return 'timeout';
    }

    private static function drainCoreTransports(
        ContainerInterface $receiverLocator,
        MessageBusInterface $messageBus,
        bool &$hadMessage,
    ): bool {
        $worked = false;
        foreach (self::CORE_TRANSPORTS as $transportName) {
            $receiver = self::transport($receiverLocator, $transportName);
            foreach ($receiver->get() as $envelope) {
                try {
                    $messageBus->dispatch($envelope->with(new ReceivedStamp($transportName)));
                    $receiver->ack($envelope);
                } catch (\Throwable $e) {
                    $receiver->reject($envelope);
                    throw $e;
                }
                $hadMessage = true;
                $worked = true;
            }
        }

        return $worked;
    }

    /**
     * Poll Temporal (journal + activité) : pas d'enveloppe Messenger, effet de bord dans {@see TransportInterface::get()}.
     *
     * Vérifie la complétion du workflow ENTRE chaque transport pour éviter un long-poll inutile sur le transport
     * d'activités si le journal vient de produire {@see ExecutionCompleted}.
     */
    private static function pollTemporalMirrorTransportsIfRegistered(
        ContainerInterface $receiverLocator,
        EventStoreInterface $eventStore,
        string $executionId,
    ): void {
        foreach (self::TEMPORAL_MIRROR_TRANSPORTS as $transportName) {
            if (null !== WorkflowQueryEvaluator::lastExecutionResult($eventStore, $executionId)) {
                return;
            }
            if (!self::receiverHasTransport($receiverLocator, $transportName)) {
                continue;
            }
            $receiver = self::transport($receiverLocator, $transportName);
            foreach ($receiver->get() as $envelope) {
                // Les transports Temporal actuels renvoient un itérable vide ; l'effet utile est le poll gRPC dans get().
            }
        }
    }

    private static function receiverHasTransport(ContainerInterface $receiverLocator, string $transportName): bool
    {
        return $receiverLocator->has($transportName);
    }

    private static function transport(ContainerInterface $receiverLocator, string $transportName): TransportInterface
    {
        $receiver = $receiverLocator->get($transportName);
        if (!$receiver instanceof TransportInterface) {
            throw new \LogicException(\sprintf('Transport "%s" must implement %s.', $transportName, TransportInterface::class));
        }

        return $receiver;
    }
}
