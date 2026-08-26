<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use PHPUnit\Framework\TestCase;

/**
 * Les verdicts de la sonde §1.3, rendus impossibles à subir.
 *
 * Le serveur rabote en silence : demander 60 s de `startToClose` sous 10 s de `scheduleToClose`
 * fait enregistrer 10 s, sans erreur. L'objet-valeur refuse la combinaison à la construction —
 * c'est la seule différence entre une borne qu'on croit avoir et une borne qu'on a.
 *
 * @see openspec/changes/temporal-nexus-support/design.md
 * @see tests/integration/Temporal/NexusOperationBoundsTest.php
 */
final class NexusOperationTimeoutsTest extends TestCase
{
    public function testNoBoundAtAll(): void
    {
        $timeouts = NexusOperationTimeouts::none();

        self::assertTrue($timeouts->areUnbounded());
        self::assertNull($timeouts->scheduleToClose);
        self::assertNull($timeouts->scheduleToStart);
        self::assertNull($timeouts->startToClose);
    }

    public function testAScheduleToStartLongerThanTheEnvelopeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schedule-to-close');

        new NexusOperationTimeouts(
            scheduleToClose: Duration::seconds(10),
            scheduleToStart: Duration::seconds(60),
        );
    }

    public function testAStartToCloseLongerThanTheEnvelopeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schedule-to-close');

        new NexusOperationTimeouts(
            scheduleToClose: Duration::seconds(10),
            startToClose: Duration::seconds(60),
        );
    }

    public function testASubBoundEqualToTheEnvelopeIsAccepted(): void
    {
        $timeouts = new NexusOperationTimeouts(
            scheduleToClose: Duration::seconds(10),
            scheduleToStart: Duration::seconds(10),
            startToClose: Duration::seconds(10),
        );

        self::assertFalse($timeouts->areUnbounded());
    }

    public function testAnInfiniteEnvelopeClampsNothing(): void
    {
        // Sur le fil, cette enveloppe s'écrit 0 — que le serveur lit « pas de borne » et qui ne
        // rabote rien. L'infini du domaine dit la même chose sans le déguiser en zéro seconde.
        $timeouts = new NexusOperationTimeouts(
            scheduleToClose: Duration::infinity(),
            startToClose: Duration::seconds(3600),
        );

        self::assertSame(3600.0, $timeouts->startToClose?->toSeconds());
    }

    public function testSubBoundsWithoutAnEnvelopeAreAccepted(): void
    {
        $timeouts = new NexusOperationTimeouts(
            scheduleToStart: Duration::seconds(60),
            startToClose: Duration::seconds(600),
        );

        self::assertFalse($timeouts->areUnbounded());
    }

    public function testWideningTheEnvelopeThroughAWitherIsRevalidated(): void
    {
        $timeouts = new NexusOperationTimeouts(startToClose: Duration::seconds(60));

        $this->expectException(\InvalidArgumentException::class);
        $timeouts->withScheduleToClose(Duration::seconds(10));
    }

    public function testAWitherKeepsTheOtherBounds(): void
    {
        $timeouts = NexusOperationTimeouts::none()
            ->withScheduleToClose(Duration::seconds(600))
            ->withStartToClose(Duration::seconds(60));

        self::assertSame(600.0, $timeouts->scheduleToClose?->toSeconds());
        self::assertSame(60.0, $timeouts->startToClose?->toSeconds());
    }
}
