<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\EventListener;

use Gplanchat\Durable\Bundle\Messenger\DurableWorkerInspection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnFailureLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;

/**
 * The Temporal workers do their work inside get() and hand Messenger no message, so --limit and
 * --failure-limit never count anything and never stop them (#353). A worker started with either on
 * one of them is told so, with what does stop it. A warning, not a refusal: the worker keeps
 * running, as it would have, and nothing is lost.
 *
 * Registered on the Temporal backend only: elsewhere these names are real transports, where the
 * limits work. Hooked on WorkerStartedEvent, which messenger:consume and durable:worker both reach.
 */
final class WarnOnIgnoredWorkerLimitsListener
{
    public function __construct(
        private readonly DurableWorkerInspection $inspection,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(WorkerStartedEvent $event): void
    {
        $ignored = array_keys(array_filter([
            '--limit' => $this->inspection->hasRunListener(StopWorkerOnMessageLimitListener::class, WorkerRunningEvent::class),
            '--failure-limit' => $this->inspection->hasRunListener(StopWorkerOnFailureLimitListener::class, WorkerMessageFailedEvent::class),
        ]));
        if ([] === $ignored) {
            return;
        }

        $workers = $this->inspection->durableTransports($event->getWorker()->getMetadata()->getTransportNames());
        if ([] === $workers) {
            return;
        }

        $this->logger->warning(\sprintf(
            '%s never stop the Temporal workers "%s": they hand Messenger no message to count. Recycle them with --time-limit or --memory-limit instead.',
            implode(' and ', $ignored),
            implode('", "', $workers),
        ));
    }
}
