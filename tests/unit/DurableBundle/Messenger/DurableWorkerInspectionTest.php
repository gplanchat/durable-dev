<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Messenger;

use Gplanchat\Durable\Bundle\Messenger\DurableWorkerInspection;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnFailureLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * What the listeners guarding a starting worker ask (#444, and #353's limits warning): which of
 * its transports are Durable's, and which of `messenger:consume`'s run-scoped listeners are on.
 */
final class DurableWorkerInspectionTest extends TestCase
{
    public function testDurableTransportsAreWhereDurableRoutesItsMessages(): void
    {
        $inspection = $this->inspection(temporal: false);

        self::assertSame(['durable_workflows', 'durable_activities'], $inspection->durableTransports(['durable_workflows', 'durable_activities', 'mailer']));
        self::assertTrue($inspection->isInMemory('durable_workflows'));
        self::assertFalse($inspection->isInMemory('mailer'));
    }

    public function testOnTemporalTheDurableTransportsAreTheBundlesReceivers(): void
    {
        self::assertSame(['durable_workflows', 'durable_nexus'], $this->inspection(temporal: true)->durableTransports(['durable_workflows', 'durable_nexus', 'mailer']));
    }

    public function testTheRunScopedListenersAreReadBack(): void
    {
        $dispatcher = new EventDispatcher();
        $inspection = $this->inspection(temporal: false, dispatcher: $dispatcher);
        self::assertFalse($inspection->hasRunListener(StopWorkerOnMessageLimitListener::class, WorkerRunningEvent::class));

        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(3));
        $dispatcher->addSubscriber(new StopWorkerOnFailureLimitListener(2));

        self::assertTrue($inspection->hasRunListener(StopWorkerOnMessageLimitListener::class, WorkerRunningEvent::class));
        self::assertTrue($inspection->hasRunListener(StopWorkerOnFailureLimitListener::class, WorkerMessageFailedEvent::class));
    }

    private function inspection(bool $temporal, ?EventDispatcher $dispatcher = null): DurableWorkerInspection
    {
        $transports = ['durable_workflows' => new InMemoryTransport(), 'durable_activities' => new InMemoryTransport(), 'sync' => new SyncTransport($this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class)), 'mailer' => new SyncTransport($this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class))];
        $locator = new ServiceLocator(array_map(static fn(object $t): \Closure => static fn(): object => $t, $transports));
        $senders = new SendersLocator([
            ResumeWorkflowMessage::class => ['durable_workflows'],
            FireWorkflowTimersMessage::class => ['durable_workflows', 'sync'],
            ActivityMessage::class => ['durable_activities'],
        ], $locator);

        return new DurableWorkerInspection($senders, $locator, 'durable_activities', $temporal, $dispatcher ?? new EventDispatcher());
    }
}
