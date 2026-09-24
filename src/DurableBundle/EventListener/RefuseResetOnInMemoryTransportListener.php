<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\EventListener;

use Gplanchat\Durable\Bundle\Messenger\DurableWorkerInspection;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;

/**
 * Refuses a worker that would lose Durable's messages without a word (#444).
 *
 * An `in-memory://` transport only holds what its own process sent, and after every message the
 * worker resets services, which empties it: the activity a workflow just queued is gone, and the
 * run waits on `ActivityScheduled` for good. No use of that combination is correct, so it stops
 * here, before the first message, with both ways out.
 */
final class RefuseResetOnInMemoryTransportListener
{
    public function __construct(
        private readonly DurableWorkerInspection $inspection,
    ) {}

    public function __invoke(WorkerStartedEvent $event): void
    {
        if (!$this->inspection->hasRunListener(ResetServicesListener::class, WorkerRunningEvent::class)) {
            return;
        }

        $lost = array_values(array_filter(
            $this->inspection->durableTransports($event->getWorker()->getMetadata()->getTransportNames()),
            $this->inspection->isInMemory(...),
        ));
        if ([] === $lost) {
            return;
        }

        throw new \LogicException(\sprintf(
            'Durable transport(s) "%s" are in-memory, and this worker resets services after each message, which empties them: '
            . 'the activity a workflow queues would be lost and the run would never end. In one process (tests), consume with --no-reset, '
            . 'or drain the run in the test (DurableBundleTestTrait). Across processes, use real transports (the several-processes profile of the guide).',
            implode('", "', $lost),
        ));
    }
}
