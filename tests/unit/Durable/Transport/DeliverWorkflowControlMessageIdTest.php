<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Transport;

use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * A signal or an update is one logical call however many times Messenger delivers it: its id is
 * drawn once, when the message is built, and travels with it (#333).
 *
 * @internal
 */
#[CoversClass(DeliverWorkflowSignalMessage::class)]
#[CoversClass(DeliverWorkflowUpdateMessage::class)]
final class DeliverWorkflowControlMessageIdTest extends TestCase
{
    public function testEachSignalMessageDrawsItsOwnIdAndARedeliveryKeepsIt(): void
    {
        $message = new DeliverWorkflowSignalMessage('exec-1', 'approve');

        self::assertNotSame('', $message->requestId);
        self::assertNotSame($message->requestId, (new DeliverWorkflowSignalMessage('exec-1', 'approve'))->requestId);
        self::assertSame($message->requestId, self::redelivered($message)->requestId);
    }

    public function testEachUpdateMessageDrawsItsOwnIdAndARedeliveryKeepsIt(): void
    {
        $message = new DeliverWorkflowUpdateMessage('exec-1', 'setDiscount');

        self::assertNotSame('', $message->updateId);
        self::assertNotSame($message->updateId, (new DeliverWorkflowUpdateMessage('exec-1', 'setDiscount'))->updateId);
        self::assertSame($message->updateId, self::redelivered($message)->updateId);
    }

    public function testAnIdTheSenderGivesIsKept(): void
    {
        self::assertSame('sig-42', (new DeliverWorkflowSignalMessage('exec-1', 'approve', [], 'sig-42'))->requestId);
        self::assertSame('upd-42', (new DeliverWorkflowUpdateMessage('exec-1', 'setDiscount', [], 'upd-42'))->updateId);
    }

    /**
     * @template T of object
     *
     * @param T $message
     *
     * @return T
     */
    private static function redelivered(object $message): object
    {
        $serializer = new PhpSerializer();

        /** @var T */
        return $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();
    }
}
