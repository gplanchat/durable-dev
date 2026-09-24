<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\EventListener;

use Gplanchat\Durable\Bundle\EventListener\WarnOnIgnoredWorkerLimitsListener;
use Gplanchat\Durable\Bundle\Messenger\DurableWorkerInspection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnFailureLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * The Temporal workers hand Messenger no message, so --limit and --failure-limit never stop them
 * (#353). A worker started with either on one of them is told so, with what works instead.
 */
final class WarnOnIgnoredWorkerLimitsListenerTest extends TestCase
{
    public function testALimitOnATemporalWorkerIsReportedWithWhatWorksInstead(): void
    {
        [$logs, $listener, $dispatcher] = $this->listener();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(10));
        $dispatcher->addSubscriber(new StopWorkerOnFailureLimitListener(3));

        $listener(new WorkerStartedEvent($this->worker(['durable_workflows', 'async'], $dispatcher)));

        self::assertCount(1, $logs->records);
        [$level, $message] = $logs->records[0];
        self::assertSame('warning', $level);
        self::assertStringContainsString('--limit', $message);
        self::assertStringContainsString('--failure-limit', $message);
        self::assertStringContainsString('durable_workflows', $message);
        self::assertStringNotContainsString('async', $message, 'a transport of the application is not one of these workers');
        self::assertStringContainsString('--time-limit', $message);
    }

    public function testWithoutThoseOptionsNothingIsSaid(): void
    {
        [$logs, $listener, $dispatcher] = $this->listener();

        $listener(new WorkerStartedEvent($this->worker(['durable_workflows'], $dispatcher)));

        self::assertSame([], $logs->records);
    }

    public function testOnTheApplicationsOwnTransportsTheLimitsWork(): void
    {
        [$logs, $listener, $dispatcher] = $this->listener();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(10));

        $listener(new WorkerStartedEvent($this->worker(['async'], $dispatcher)));

        self::assertSame([], $logs->records);
    }

    /**
     * @return array{object{records: list<array{string, string}>}, WarnOnIgnoredWorkerLimitsListener, EventDispatcher}
     */
    private function listener(): array
    {
        $dispatcher = new EventDispatcher();
        $logs = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };

        return [$logs, new WarnOnIgnoredWorkerLimitsListener(new DurableWorkerInspection(null, null, null, true, $dispatcher), $logs), $dispatcher];
    }

    /**
     * @param list<string> $transports
     */
    private function worker(array $transports, EventDispatcher $dispatcher): Worker
    {
        return new Worker(array_fill_keys($transports, new InMemoryTransport()), new MessageBus(), $dispatcher);
    }
}
