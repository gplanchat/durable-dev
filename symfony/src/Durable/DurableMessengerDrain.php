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
 * Drains the workflow / activity transports until {@see ExecutionCompleted}.
 * Timer wake-ups are scheduled by the core ({@see \Gplanchat\Durable\Handler\ResumeWorkflowHandler}),
 * not by a loop that spams {@see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage}.
 *
 * Every envelope returned by {@see TransportInterface::get()} must be {@see TransportInterface::ack() acked}
 * (Symfony worker behaviour) — otherwise the in-memory transports would hand back the same message on every turn.
 *
 * With the **native Temporal backend** ({@see WorkflowTaskRunner} + {@see TemporalHistoryCursor}), activities
 * go through {@code durable_temporal_activity} (gRPC poll): {@code durable_temporal_journal} and
 * {@code durable_temporal_activity} must be consumed too, like {@code messenger:consume}.
 */
final class DurableMessengerDrain
{
    /** @var list<string> */
    private const CORE_TRANSPORTS = ['durable_workflows', 'durable_activities'];

    /** @var list<string> */
    private const TEMPORAL_MIRROR_TRANSPORTS = ['durable_temporal_journal', 'durable_temporal_activity'];

    /** Max wall-clock time (seconds): workflows using {@see WorkflowEnvironment::delay} rely on delayed Messenger messages. */
    private const MAX_DRAIN_SECONDS = 120.0;

    /**
     * @return bool true if an {@see ExecutionCompleted} is present for this executionId
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
     * Polls Temporal (journal + activity): no Messenger envelope, the side effect is in {@see TransportInterface::get()}.
     *
     * Checks workflow completion BETWEEN each transport to avoid a pointless long-poll on the activity
     * transport if the journal has just produced {@see ExecutionCompleted}.
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
                // The current Temporal transports return an empty iterable; the useful effect is the gRPC poll in get().
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
