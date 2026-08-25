<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Durable\Activity\RetryLimit;

use Gplanchat\Bridge\Temporal\Profiler\TemporalEventConverter;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Temporal\Api\Enums\V1\EventType;

/**
 * Les chemins d'échec vus par le serveur : le `kind` doit survivre à l'aller-retour, et une
 * exception déclarée non-retryable doit vraiment arrêter la RetryPolicy côté serveur.
 */
final class WorkflowFailurePathsTest extends TemporalServerTestCase
{
    public function testUnhandledActivityFailureFailsTheWorkflowOnTheServer(): void
    {
        $executionId = $this->startWorkflow('FailsOnActivity', []);
        $event = $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);

        $failure = $event->getWorkflowExecutionFailedEventAttributes()?->getFailure();
        self::assertNotNull($failure);
        self::assertStringContainsString('activity exploded', $failure->getMessage());
    }

    public function testTheFailureKindSurvivesTheRoundTripThroughTheServer(): void
    {
        // Le kind voyage dans les details de l'ApplicationFailureInfo : c'est ce qui permet de
        // reconstruire un WorkflowExecutionFailed typé depuis l'historique.
        $executionId = $this->startWorkflow('FailsOnActivity', []);
        $event = $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);

        $decoded = (new TemporalEventConverter($executionId))->convert($event);

        self::assertInstanceOf(WorkflowExecutionFailed::class, $decoded);
        self::assertSame(WorkflowExecutionFailed::KIND_UNHANDLED_ACTIVITY, $decoded->kind());
        self::assertSame('boom', $decoded->context()['activityName'] ?? null);
    }

    public function testAnActivityWithoutMaxAttemptsRetriesIndefinitely(): void
    {
        // Référence de l'alignement in-memory : sans maximum_attempts, le serveur retente sans
        // fin et le workflow ne se termine jamais.
        $executionId = $this->startWorkflow('UnboundedRetry', []);
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);

        // Après plusieurs secondes, toujours aucune issue terminale.
        sleep(4);
        $names = $this->historyEventNames($executionId);

        self::assertNotContains('EVENT_TYPE_WORKFLOW_EXECUTION_FAILED', $names);
        self::assertNotContains('EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED', $names);
    }

    public function testNonRetryableExceptionStopsTheServerRetryPolicy(): void
    {
        // RetryLimit::ofAttempts(5) dans la RetryPolicy, mais l'exception est déclarée non-retryable :
        // le serveur ne doit planifier qu'une seule tentative.
        $executionId = $this->startWorkflow('NonRetryable', []);
        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED);

        $starts = array_filter(
            $this->historyEventNames($executionId),
            static fn (string $name): bool => 'EVENT_TYPE_ACTIVITY_TASK_STARTED' === $name,
        );

        self::assertCount(1, $starts, 'une exception non-retryable ne doit pas être retentée');
    }
}
