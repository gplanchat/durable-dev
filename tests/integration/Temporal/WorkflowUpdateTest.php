<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Durable\Exception\DurableUpdateFailedException;

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
            self::assertStringContainsString('approbation refusée', $e->getMessage());
        }

        // The execution is intact: it still answers, and runs to the end.
        self::assertSame(['ok' => true, 'by' => 'alice'], $this->workflowClient()->update($workflowId, 'approve', ['by' => 'alice']));
        $result = $this->workflowClient()->pollForCompletion($executionId, 250, 160);
        self::assertSame(['ok' => true, 'by' => 'alice'], $result['approved'] ?? null);
    }
}
