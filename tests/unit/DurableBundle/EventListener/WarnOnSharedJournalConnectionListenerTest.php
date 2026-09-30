<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\EventListener;

use Gplanchat\Durable\Bundle\EventListener\WarnOnSharedJournalConnectionListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class WarnOnSharedJournalConnectionListenerTest extends TestCase
{
    public function testAWorkerStartLogsOneWarningThatSaysWhatToDo(): void
    {
        $logs = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
        $worker = new Worker(['durable_activities' => new InMemoryTransport()], new MessageBus(), new EventDispatcher());

        (new WarnOnSharedJournalConnectionListener($logs, 'doctrine.dbal.default_connection'))(new WorkerStartedEvent($worker));

        self::assertCount(1, $logs->records);
        [$level, $message] = $logs->records[0];
        self::assertSame('warning', $level);
        self::assertStringContainsString('"doctrine.dbal.default_connection"', $message);
        self::assertStringContainsString('durable.dbal.connection', $message);
        self::assertStringContainsString('DUR054', $message);
    }
}
