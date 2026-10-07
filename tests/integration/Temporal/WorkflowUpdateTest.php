<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
use Temporal\Api\Common\V1\WorkflowExecution;

/**
 * An update that answers, against a real server.
 *
 * This is the whole path: the client sends the update, the server hands it to the worker as a
 * protocol message *outside the history*, the handler produces the outcome, the worker accepts and
 * answers on the same task, and the caller receives the return value. No part of that chain can be
 * checked against a fake server — hence this test (tasks 5.5 and 7.3).
 */
final class WorkflowUpdateTest extends TemporalServerTestCase
{
    public function testAnUpdateHandlerAnswersItsCaller(): void
    {
        $executionId = $this->startWorkflow('Updatable', []);

        $answer = $this->workflowClient()->update($this->workflowId($executionId), 'approve', ['by' => 'alice']);

        self::assertSame(['ok' => true, 'by' => 'alice'], $answer);

        $result = $this->workflowClient()->pollForCompletion($executionId, 250, 160);
        self::assertSame(['ok' => true, 'by' => 'alice'], $result['approved'] ?? null);
    }

    public function testAFailingUpdateDoesNotFailTheWorkflow(): void
    {
        $executionId = $this->startWorkflow('Updatable', []);
        $workflowId = $this->workflowId($executionId);

        try {
            $this->workflowClient()->update($workflowId, 'refuse', ['by' => 'bob']);
            self::fail('the update should have failed');
        } catch (DurableUpdateFailedException $e) {
            self::assertStringContainsString('approval refused', $e->getMessage());
        }

        // The execution is intact: it still answers, and runs to the end.
        self::assertSame(['ok' => true, 'by' => 'alice'], $this->workflowClient()->update($workflowId, 'approve', ['by' => 'alice']));
        $result = $this->workflowClient()->pollForCompletion($executionId, 250, 160);
        self::assertSame(['ok' => true, 'by' => 'alice'], $result['approved'] ?? null);
    }

    /**
     * DUR051: the worker's command buffer records nothing for an update on Temporal
     * (`recordUpdateHandled()` is a documented delegation), because the server writes the record
     * itself from the protocol messages the worker hands back. This is the proof that it does.
     */
    public function testTheServerRecordsTheUpdateTheWorkerHandled(): void
    {
        $executionId = $this->startWorkflow('Updatable', []);
        $this->workflowClient()->update($this->workflowId($executionId), 'approve', ['by' => 'alice']);
        $this->workflowClient()->pollForCompletion($executionId, 250, 160);

        $names = $this->historyEventNames($executionId);

        self::assertContains('EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_ACCEPTED', $names);
        self::assertContains('EVENT_TYPE_WORKFLOW_EXECUTION_UPDATE_COMPLETED', $names);
    }

    /**
     * #803: the history a worker replays pairs each update with its own outcome, by the update id
     * the server writes into the completed event's `meta`.
     */
    public function testTheReplayedHistoryPairsEachUpdateWithItsOwnOutcome(): void
    {
        $executionId = $this->startWorkflow('Updatable', []);
        $workflowId = $this->workflowId($executionId);

        try {
            $this->workflowClient()->update($workflowId, 'refuse', ['by' => 'bob'], 'upd-refuse');
            self::fail('the update should have failed');
        } catch (DurableUpdateFailedException) {
        }
        $this->workflowClient()->update($workflowId, 'approve', ['by' => 'alice'], 'upd-approve');
        $this->workflowClient()->pollForCompletion($executionId, 250, 160);

        $cursor = new TemporalHistoryCursor($this->client, $this->connection);
        $history = TemporalExecutionHistory::fromEvents($cursor->events(new WorkflowExecution(['workflow_id' => $workflowId])));

        self::assertSame(['ok' => true, 'by' => 'alice'], $history->updateOutcome('upd-approve')?->result);
        self::assertInstanceOf(DurableUpdateFailedException::class, $history->updateOutcome('upd-refuse')?->failed);
    }
}
