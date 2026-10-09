<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\DurableModule\Runtime\TableQueue\Queues;
use PHPUnit\Framework\TestCase;

/** #736: a worker gives a message back without acknowledging it. */
final class TheTableQueueGivesAMessageBackTest extends TestCase
{
    public function testAReleasedMessageStaysInTheQueueAndComesBackAfterItsDelay(): void
    {
        JournalHarness::adapter();
        $backend = BenchRuntime::factory()->database();
        $backend->queue->enqueue(Queues::RESUME, 'x');
        $taken = $backend->queue->take(Queues::RESUME);
        self::assertNotNull($taken);

        self::assertTrue($backend->queue->release($taken, 0.3));

        self::assertSame(1, (int) $backend->connection->fetchOne('SELECT COUNT(*) FROM durable_queue'));
        self::assertNull($backend->queue->take(Queues::RESUME), 'delivered before its delay');
        usleep(400_000);
        self::assertSame($taken->id, $backend->queue->take(Queues::RESUME)?->id);
    }
}
