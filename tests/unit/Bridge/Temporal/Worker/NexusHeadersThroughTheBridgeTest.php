<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use PHPUnit\Framework\TestCase;

/**
 * §3.1 and §3.2 — the headers travel through the port and reach the command.
 *
 * Taken together: widening the port without the bridge writing anything would ship a parameter
 * that is accepted then ignored, which is worse than not having it — a caller would believe it
 * was setting a header.
 *
 * What these tests cannot say: whether the server accepts. That is §4.1, against a real server,
 * and it is exactly what other commands of this bridge have already lacked.
 */
final class NexusHeadersThroughTheBridgeTest extends TestCase
{
    public function testTheHeadersReachTheCommand(): void
    {
        $command = $this->schedule(NexusOperationHeaders::of(['x-correlation' => 'abc-123', 'x-tenant' => 'acme']));

        $written = [];
        foreach ($command->getScheduleNexusOperationCommandAttributes()?->getNexusHeader() ?? [] as $k => $v) {
            $written[(string) $k] = (string) $v;
        }
        ksort($written);

        self::assertSame(['x-correlation' => 'abc-123', 'x-tenant' => 'acme'], $written);
    }

    public function testAKeyGivenInUpperCaseTravelsLowercased(): void
    {
        // The coercion belongs to the value object, not to the bridge: what the caller holds must
        // already be what the server will keep, otherwise reading its own value back lies.
        $command = $this->schedule(NexusOperationHeaders::of(['X-Correlation' => 'abc-123']));

        $written = [];
        foreach ($command->getScheduleNexusOperationCommandAttributes()?->getNexusHeader() ?? [] as $k => $v) {
            $written[(string) $k] = (string) $v;
        }

        self::assertSame(['x-correlation' => 'abc-123'], $written);
    }

    public function testNoHeaderWritesNoHeader(): void
    {
        // An empty map is not the same thing as an absent map for whoever reads a history back.
        $command = $this->schedule(NexusOperationHeaders::none());

        self::assertCount(0, $command->getScheduleNexusOperationCommandAttributes()?->getNexusHeader() ?? []);
    }

    private function schedule(NexusOperationHeaders $headers): \Temporal\Api\Command\V1\Command
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
        $buffer->scheduleNexusOperation(
            'op-1',
            NexusEndpoint::named('paiements'),
            NexusService::named('facturation'),
            NexusOperationName::named('encaisser'),
            ['montant' => 10],
            new NexusOperationTimeouts(scheduleToClose: Duration::seconds(30.0)),
            $headers,
        );

        $commands = $buffer->flush();
        self::assertCount(1, $commands);

        return $commands[0];
    }
}
