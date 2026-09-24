<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Messenger\DeliverWorkflowSignalToTemporalHandler;
use Gplanchat\Bridge\Temporal\Messenger\DeliverWorkflowUpdateToTemporalHandler;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use unit\Bridge\Temporal\RecordingWorkflowClient;

/**
 * On Temporal native the cluster is the journal: a signal or an update sent through Messenger must
 * reach it, not a local store nobody replays (#333).
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
            new DeliverWorkflowSignalMessage('exec-1', 'approve', ['by' => 'alice']),
        );

        self::assertSame([['signal', 'wf-exec-1', 'approve', ['by' => 'alice']]], $client->calls);
    }

    public function testAnUpdateReachesTheClusterUnderTheWorkflowIdOfTheExecution(): void
    {
        $client = new RecordingWorkflowClient();

        (new DeliverWorkflowUpdateToTemporalHandler($client))(
            new DeliverWorkflowUpdateMessage('exec-1', 'setDiscount', ['percent' => 10]),
        );

        self::assertSame([['update', 'wf-exec-1', 'setDiscount', ['percent' => 10]]], $client->calls);
    }
}
