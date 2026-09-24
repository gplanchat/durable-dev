<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Messenger\DeliverWorkflowSignalToTemporalHandler;
use Gplanchat\Bridge\Temporal\Messenger\DeliverWorkflowUpdateToTemporalHandler;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use unit\Bridge\Temporal\RecordingWorkflowClient;

/**
 * On Temporal native the cluster is the journal: a signal or an update sent through Messenger must
 * reach it, not a local store nobody replays, and a redelivery must not run it twice (#333).
 *
 * @internal
 */
#[CoversClass(DeliverWorkflowSignalToTemporalHandler::class)]
#[CoversClass(DeliverWorkflowUpdateToTemporalHandler::class)]
final class DeliverWorkflowControlToTemporalTest extends TestCase
{
    public function testASignalReachesTheClusterUnderTheWorkflowIdOfTheExecution(): void
    {
        $client = new RecordingWorkflowClient();

        (new DeliverWorkflowSignalToTemporalHandler($client))(
            new DeliverWorkflowSignalMessage('exec-1', 'approve', ['by' => 'alice'], 'sig-1'),
        );

        self::assertSame([['signal', 'wf-exec-1', 'approve', ['by' => 'alice'], 'sig-1']], $client->calls);
    }

    public function testAnUpdateReachesTheClusterUnderTheWorkflowIdOfTheExecution(): void
    {
        $client = new RecordingWorkflowClient();

        (new DeliverWorkflowUpdateToTemporalHandler($client))(
            new DeliverWorkflowUpdateMessage('exec-1', 'setDiscount', ['percent' => 10], 'upd-1'),
        );

        self::assertSame([['update', 'wf-exec-1', 'setDiscount', ['percent' => 10], 'upd-1']], $client->calls);
    }

    public function testASignalRedeliveredAfterALostAnswerCarriesTheSameRequestId(): void
    {
        $client = new RecordingWorkflowClient();
        $handler = new DeliverWorkflowSignalToTemporalHandler($client);

        self::deliverTwice($handler, $client, new DeliverWorkflowSignalMessage('exec-1', 'approve'));

        self::assertCount(2, $client->calls);
        self::assertNotNull($client->calls[0][4]);
        self::assertSame($client->calls[0][4], $client->calls[1][4]);
    }

    public function testAnUpdateRedeliveredAfterALostAnswerCarriesTheSameUpdateId(): void
    {
        $client = new RecordingWorkflowClient();
        $handler = new DeliverWorkflowUpdateToTemporalHandler($client);

        self::deliverTwice($handler, $client, new DeliverWorkflowUpdateMessage('exec-1', 'setDiscount'));

        self::assertCount(2, $client->calls);
        self::assertNotNull($client->calls[0][4]);
        self::assertSame($client->calls[0][4], $client->calls[1][4]);
    }

    /**
     * A message queued before the upgrade has no id in its serialized form: it must still be
     * delivered, not fail on an uninitialized property and end in the failure transport.
     */
    public function testAMessageQueuedBeforeTheUpgradeIsStillDelivered(): void
    {
        // The wire form of the two messages on main before #333, as PhpSerializer wrote them.
        $signal = unserialize('O:56:"Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage":3:{s:10:"signalName";s:7:"approve";s:11:"executionId";s:6:"exec-1";s:7:"payload";a:1:{s:2:"by";s:5:"alice";}}');
        $update = unserialize('O:56:"Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage":3:{s:11:"executionId";s:6:"exec-1";s:10:"updateName";s:11:"setDiscount";s:9:"arguments";a:1:{s:7:"percent";i:10;}}');
        self::assertInstanceOf(DeliverWorkflowSignalMessage::class, $signal);
        self::assertInstanceOf(DeliverWorkflowUpdateMessage::class, $update);
        $client = new RecordingWorkflowClient();

        (new DeliverWorkflowSignalToTemporalHandler($client))($signal);
        (new DeliverWorkflowUpdateToTemporalHandler($client))($update);

        self::assertCount(2, $client->calls);
        self::assertSame(['signal', 'wf-exec-1', 'approve', ['by' => 'alice']], \array_slice($client->calls[0], 0, 4));
        self::assertSame(['update', 'wf-exec-1', 'setDiscount', ['percent' => 10]], \array_slice($client->calls[1], 0, 4));
        self::assertNotSame('', (string) $client->calls[0][4]);
        self::assertNotSame('', (string) $client->calls[1][4]);
    }

    /**
     * What a Messenger retry does: the first attempt fails after the cluster accepted the call, and
     * the message comes back through the transport's serializer.
     */
    private static function deliverTwice(callable $handler, RecordingWorkflowClient $client, object $message): void
    {
        $client->loseNextAnswer = true;

        try {
            $handler($message);
            self::fail('The first attempt should have lost its answer.');
        } catch (\RuntimeException) {
        }

        $serializer = new PhpSerializer();
        $handler($serializer->decode($serializer->encode(new Envelope($message)))->getMessage());
    }
}
