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
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Command\V1\ScheduleNexusOperationCommandAttributes;
use Temporal\Api\Enums\V1\CommandType;

/**
 * La commande `ScheduleNexusOperation` telle que le pont la construit.
 *
 * Les bornes suivent le verdict de la sonde §1.3 : le serveur n'en défausse aucune, et une borne
 * absente doit le rester. Les poser à zéro « pour remplir » changerait le sens — zéro veut dire
 * « pas de borne », pas « zéro seconde ».
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §4.1
 * @see tests/integration/Temporal/NexusOperationBoundsTest.php
 */
#[RequiresPhpExtension('grpc')]
final class TemporalNexusScheduleCommandTest extends TestCase
{
    public function testTheCommandCarriesTheThreeNamesAndTheInput(): void
    {
        $attrs = $this->schedule(NexusOperationTimeouts::none());

        self::assertSame('billing-endpoint', $attrs->getEndpoint());
        self::assertSame('billing', $attrs->getService());
        self::assertSame('charge', $attrs->getOperation());
        self::assertNotNull($attrs->getInput());
    }

    public function testAnAbsentBoundStaysAbsent(): void
    {
        // Sondé : le serveur n'applique aucun défaut et n'enregistre que ce qu'on lui donne.
        $attrs = $this->schedule(NexusOperationTimeouts::none());

        self::assertNull($attrs->getScheduleToCloseTimeout());
        self::assertNull($attrs->getScheduleToStartTimeout());
        self::assertNull($attrs->getStartToCloseTimeout());
    }

    public function testEachBoundIsCarriedWhenItIsSet(): void
    {
        $attrs = $this->schedule(new NexusOperationTimeouts(
            scheduleToClose: Duration::seconds(600),
            scheduleToStart: Duration::seconds(30),
            startToClose: Duration::seconds(120),
        ));

        self::assertSame(600, $attrs->getScheduleToCloseTimeout()?->getSeconds());
        self::assertSame(30, $attrs->getScheduleToStartTimeout()?->getSeconds());
        self::assertSame(120, $attrs->getStartToCloseTimeout()?->getSeconds());
    }

    public function testAnInfiniteEnvelopeIsSentAsZeroBecauseThatIsHowTemporalSpellsUnbounded(): void
    {
        // Duration::infinity() côté domaine, 0 sur le fil : c'est la convention du serveur, mesurée
        // en §1.3 — un scheduleToClose à 0 ne rabote pas les sous-bornes.
        $attrs = $this->schedule(new NexusOperationTimeouts(scheduleToClose: Duration::infinity()));

        self::assertSame(0, $attrs->getScheduleToCloseTimeout()?->getSeconds());
    }

    private function schedule(NexusOperationTimeouts $timeouts): ScheduleNexusOperationCommandAttributes
    {
        $buffer = new TemporalWorkflowCommandBuffer(
            new TemporalConnection(target: '127.0.0.1:7233', namespace: 'durable-test'),
            'exec-1',
        );

        $buffer->scheduleNexusOperation(
            'op-1',
            NexusEndpoint::named('billing-endpoint'),
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            ['amount' => 10],
            $timeouts,
            NexusOperationHeaders::none(),
        );

        $commands = $buffer->flush();
        self::assertCount(1, $commands);
        self::assertSame(CommandType::COMMAND_TYPE_SCHEDULE_NEXUS_OPERATION, $commands[0]->getCommandType());

        $attrs = $commands[0]->getScheduleNexusOperationCommandAttributes();
        self::assertInstanceOf(ScheduleNexusOperationCommandAttributes::class, $attrs);

        return $attrs;
    }
}
