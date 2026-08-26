<?php

declare(strict_types=1);

namespace integration\Temporal;

use Temporal\Api\Enums\V1\EventType;

/**
 * Les assembleurs contre un vrai serveur (ADR DUR033).
 *
 * Ce que les tests unitaires ne peuvent pas voir : ils jouent la vidange synchrone, où il n'y a
 * ni fiber suspendu ni workflow task. Or `all()` est passé de N suspensions à une seule — la
 * séquence de commandes ne change pas, les frontières de tâches si, et c'est le serveur qui les
 * découpe.
 */
final class WorkflowAssemblerTest extends TemporalServerTestCase
{
    public function testAnAssemblySchedulesEveryBranchInOneWorkflowTask(): void
    {
        $executionId = $this->startWorkflow('Assembled', ['value' => 21]);
        self::assertSame(
            ['both' => [42, 'x!']],
            $this->workflowClient()->pollForCompletion($executionId, 250, 120),
        );

        $names = $this->historyEventNames($executionId);
        $scheduled = array_keys($names, EventType::name(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED), true);
        self::assertCount(2, $scheduled, 'les deux branches doivent être planifiées');

        // Une workflow task se referme sur WORKFLOW_TASK_COMPLETED, suivi des commandes qu'elle a
        // produites. Un tel événement entre les deux planifications voudrait dire deux tours.
        $between = \array_slice($names, $scheduled[0], $scheduled[1] - $scheduled[0]);
        self::assertNotContains(
            EventType::name(EventType::EVENT_TYPE_WORKFLOW_TASK_COMPLETED),
            $between,
            "les deux branches ont été planifiées en deux tours : \n" . implode("\n", $names),
        );
    }

    public function testAQuorumCompletesAndTheServerCancelsTheLosingBranches(): void
    {
        // Les perdants sont des minuteurs d'une et deux heures : sans annulation effective côté
        // serveur, l'exécution ne se terminerait pas.
        $executionId = $this->startWorkflow('Quorum', []);
        self::assertSame(
            ['keys' => [0, 1], 'values' => [2, 4]],
            $this->workflowClient()->pollForCompletion($executionId, 250, 120),
        );

        $names = $this->historyEventNames($executionId);
        self::assertCount(
            2,
            array_keys($names, EventType::name(EventType::EVENT_TYPE_TIMER_STARTED), true),
            'les deux minuteurs perdants doivent avoir été démarrés',
        );
        self::assertCount(
            2,
            array_keys($names, EventType::name(EventType::EVENT_TYPE_TIMER_CANCELED), true),
            'et retirés une fois le quorum atteint',
        );
    }
}
