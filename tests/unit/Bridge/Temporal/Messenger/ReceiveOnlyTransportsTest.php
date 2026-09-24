<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Messenger\TemporalActivityWorkerTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalJournalTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalNexusWorkerTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The three Temporal receivers do their work inside get() and hand Messenger nothing: nothing to
 * send, acknowledge or reject (#353). What each says when something is sent to it stays its own.
 */
final class ReceiveOnlyTransportsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<TransportInterface>, string}>
     */
    public static function receivers(): iterable
    {
        yield 'activities' => [TemporalActivityWorkerTransport::class, 'temporal activity worker transport is receive-only.'];
        yield 'workflows' => [TemporalJournalTransport::class, 'temporal workflow worker transport is receive-only.'];
        yield 'nexus' => [TemporalNexusWorkerTransport::class, 'temporal nexus worker transport is receive-only.'];
    }

    /**
     * @param class-string<TransportInterface> $class
     */
    #[DataProvider('receivers')]
    public function testNothingCanBeSentToIt(string $class, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        self::receiver($class)->send(new Envelope(new \stdClass()));
    }

    /**
     * @param class-string<TransportInterface> $class
     */
    #[DataProvider('receivers')]
    public function testAcknowledgingOrRejectingDoesNothing(string $class): void
    {
        $receiver = self::receiver($class);

        $receiver->ack(new Envelope(new \stdClass()));
        $receiver->reject(new Envelope(new \stdClass()));

        $this->addToAssertionCount(1);
    }

    /**
     * Built without its worker: send(), ack() and reject() never reach it, and get() would poll
     * the cluster.
     *
     * @param class-string<TransportInterface> $class
     */
    private static function receiver(string $class): TransportInterface
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
