<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Transport;

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
#[CoversClass(InMemoryActivityTransport::class)]
final class InMemoryActivityTransportTest extends \PHPUnit\Framework\TestCase
{
    #[Test]
    public function removePendingForRemovesMatchingMessageOnly(): void
    {
        $t = new InMemoryActivityTransport();
        $m1 = new ActivityMessage('ex-1', 'act-a', 'first', []);
        $m2 = new ActivityMessage('ex-1', 'act-b', 'second', []);
        $t->enqueue($m1);
        $t->enqueue($m2);

        self::assertTrue($t->removePendingFor('ex-1', 'act-b'));
        self::assertSame($m1, $t->dequeue());
        self::assertNull($t->dequeue());
    }

    #[Test]
    public function removePendingForReturnsFalseWhenAlreadyDequeued(): void
    {
        $t = new InMemoryActivityTransport();
        $t->enqueue(new ActivityMessage('ex-1', 'act-a', 'x', []));
        $t->dequeue();

        self::assertFalse($t->removePendingFor('ex-1', 'act-a'));
    }
}
