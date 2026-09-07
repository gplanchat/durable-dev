<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\TaskQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A misnamed queue produces no error: work is dropped into it and nobody comes to fetch it. The
 * object is therefore stricter than the server about what can only be a mistake.
 *
 * The server verdicts below have been probed: non-empty, a thousand characters at most, and
 * everything else accepted — including `" "` and whitespace at the edges.
 */
final class TaskQueueTest extends TestCase
{
    public function testANameIsCarriedVerbatim(): void
    {
        self::assertSame('durable-activities', TaskQueue::named('durable-activities')->name());
        self::assertSame('durable-activities', (string) TaskQueue::named('durable-activities'));
    }

    public function testAnEmptyNameIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/cannot be empty/');

        TaskQueue::named('');
    }

    public function testABlankNameIsRejectedEvenThoughTheServerAcceptsIt(): void
    {
        $this->expectExceptionMessageMatches('/no worker would ever find it/');

        TaskQueue::named('   ');
    }

    #[DataProvider('namesWithEdgeWhitespace')]
    public function testWhitespaceAtTheEdgesIsRejected(string $name): void
    {
        // The server keeps the name as it is: a worker polling the "clean" version would never
        // be matched, without the slightest message.
        $this->expectExceptionMessageMatches('/leading or trailing whitespace/');

        TaskQueue::named($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesWithEdgeWhitespace(): iterable
    {
        yield 'espace avant' => [' durable-activities'];
        yield 'espace après' => ['durable-activities '];
        yield 'saut de ligne après' => ["durable-activities\n"];
    }

    public function testAControlCharacterIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/control character/');

        TaskQueue::named("durable\tactivities");
    }

    public function testAnInternalSpaceIsAcceptedBecauseTheServerAcceptsIt(): void
    {
        // Unusual, but valid: do not invent a rule the server does not have.
        self::assertSame('my queue', TaskQueue::named('my queue')->name());
    }

    public function testTheServerLengthLimitIsEnforced(): void
    {
        self::assertSame(1000, \strlen(TaskQueue::named(str_repeat('a', 1000))->name()));

        $this->expectExceptionMessageMatches('/at most 1000 bytes, 1001 given/');
        TaskQueue::named(str_repeat('a', 1001));
    }

    public function testEqualityIsByName(): void
    {
        self::assertTrue(TaskQueue::named('a')->equals(TaskQueue::named('a')));
        self::assertFalse(TaskQueue::named('a')->equals(TaskQueue::named('b')));
    }

    public function testBoundaryCoercion(): void
    {
        self::assertSame('a', TaskQueue::from('a')->name());
        self::assertSame('a', TaskQueue::from(TaskQueue::named('a'))->name());
        self::assertNull(TaskQueue::fromNullable(null));
        self::assertNull(TaskQueue::fromNullable(''));
    }

    public function testActivityOptionsRoundTripTheQueue(): void
    {
        $options = new ActivityOptions(taskQueue: TaskQueue::named('dedicated'));
        $decoded = ActivityOptions::fromMetadata($options->toMetadata());

        self::assertSame('dedicated', $decoded?->taskQueue?->name());
    }

    public function testConnectionQueuesAreValidatedAtAssembly(): void
    {
        // The names come from a DSN: the mistake is invisible there until an execution stays
        // waiting forever.
        $connection = new TemporalConnection(target: 'localhost:7233', namespace: 'test');
        self::assertSame('durable-workflows', $connection->workflowTaskQueue->name());
        self::assertSame('durable-activities', $connection->activityTaskQueue->name());

        $this->expectExceptionMessageMatches('/leading or trailing whitespace/');
        new TemporalConnection(target: 'localhost:7233', namespace: 'test', activityTaskQueue: 'durable-activities ');
    }
}
