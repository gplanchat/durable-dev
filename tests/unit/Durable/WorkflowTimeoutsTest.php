<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\ContinueAsNewOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowStartOptions;
use Gplanchat\Durable\WorkflowTimeouts;
use PHPUnit\Framework\TestCase;

/**
 * Trois `?float` répétés à l'identique dans trois classes d'options, avec la même sérialisation
 * copiée trois fois.
 */
final class WorkflowTimeoutsTest extends TestCase
{
    public function testARunLongerThanTheExecutionIsRejected(): void
    {
        // Vérifié contre un vrai serveur : demander execution=10s + run=60s fait enregistrer
        // run=10s. La configuration est réécrite en silence — autant la refuser tout haut.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot exceed execution timeout/');

        new WorkflowTimeouts(execution: Duration::seconds(10.0), run: Duration::seconds(60.0));
    }

    public function testABoundedRunUnderTheExecutionIsAccepted(): void
    {
        $timeouts = new WorkflowTimeouts(
            execution: Duration::hours(1),
            run: Duration::minutes(10),
            task: Duration::seconds(10),
        );

        self::assertFalse($timeouts->areUnbounded());
        self::assertSame(600.0, $timeouts->run?->toSeconds());
    }

    public function testContinueAsNewRefusesAnExecutionBound(): void
    {
        // Le nouveau run appartient à l'exécution en cours et hérite sa borne : la commande
        // Temporal n'a même pas ce champ.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/inherits it/');

        new ContinueAsNewOptions(timeouts: new WorkflowTimeouts(execution: Duration::minutes(5)));
    }

    public function testDroppingTheExecutionBoundMakesTimeoutsContinuable(): void
    {
        $timeouts = new WorkflowTimeouts(execution: Duration::hours(1), run: Duration::minutes(10));

        $options = new ContinueAsNewOptions(timeouts: $timeouts->withoutExecutionBound());

        self::assertNull($options->timeouts->execution);
        self::assertSame(600.0, $options->timeouts->run?->toSeconds());
    }

    public function testTheThreeOptionClassesShareTheSameWireKeys(): void
    {
        $timeouts = new WorkflowTimeouts(run: Duration::seconds(30.0), task: Duration::seconds(5.0));

        $child = (new ChildWorkflowOptions(timeouts: $timeouts))->toSchedulingMetadata();
        $start = (new WorkflowStartOptions(timeouts: $timeouts))->toStartMetadata();
        $continued = (new ContinueAsNewOptions(timeouts: $timeouts))->toMetadata();

        foreach ([$child, $start, $continued] as $metadata) {
            self::assertSame(30.0, $metadata['workflow_run_timeout_seconds']);
            self::assertSame(5.0, $metadata['workflow_task_timeout_seconds']);
        }
    }

    public function testMetadataRoundTrip(): void
    {
        $timeouts = new WorkflowTimeouts(execution: Duration::minutes(30), run: Duration::minutes(5));
        $decoded = WorkflowTimeouts::fromMetadata($timeouts->toMetadata());

        self::assertSame(1800.0, $decoded->execution?->toSeconds());
        self::assertSame(300.0, $decoded->run?->toSeconds());
        self::assertNull($decoded->task);
    }

    public function testUnsetBoundsAreAbsentFromTheWire(): void
    {
        self::assertSame([], WorkflowTimeouts::none()->toMetadata());
        self::assertTrue(WorkflowTimeouts::fromMetadata([])->areUnbounded());
    }
}
