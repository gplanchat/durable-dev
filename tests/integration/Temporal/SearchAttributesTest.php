<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\WorkflowStartOptions;
use Temporal\Api\Enums\V1\EventType;

/**
 * The search attributes never reached the server: journalled in the metadata, then forgotten, with
 * no command ever setting them.
 *
 * Prerequisite of the test namespace:
 *
 *     temporal operator search-attribute create --name DurableOrderId --type Keyword
 *     temporal operator search-attribute create --name DurableAmount  --type Int
 */
final class SearchAttributesTest extends TemporalServerTestCase
{
    public function testAttributesReachTheServerAndComeBackInTheHistory(): void
    {
        $executionId = $this->startWorkflow('Plain', ['value' => 1], new WorkflowStartOptions(
            searchAttributes: SearchAttributes::none()
                ->keyword('DurableOrderId', 'ORD-4242')
                ->int('DurableAmount', 4242),
        ));

        $started = $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $fields = $started->getWorkflowExecutionStartedEventAttributes()?->getSearchAttributes()?->getIndexedFields();

        self::assertNotNull($fields);
        self::assertTrue($fields->offsetExists('DurableOrderId'));
        self::assertSame('ORD-4242', JsonPlainPayload::decode($fields->offsetGet('DurableOrderId')));
        self::assertSame(4242, JsonPlainPayload::decode($fields->offsetGet('DurableAmount')));
    }

    public function testTheWorkflowBecomesFindableByItsAttributes(): void
    {
        // That is the whole point of a search attribute: finding the execution again.
        $orderId = 'ORD-' . bin2hex(random_bytes(4));
        $executionId = $this->startWorkflow('Plain', ['value' => 1], new WorkflowStartOptions(
            searchAttributes: SearchAttributes::none()->keyword('DurableOrderId', $orderId),
        ));

        $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);

        $found = null;
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline && null === $found) {
            $found = $this->firstWorkflowIdMatching(\sprintf('DurableOrderId = "%s"', $orderId));
            if (null === $found) {
                usleep(500_000);
            }
        }

        self::assertSame($this->workflowId($executionId), $found);
    }

    public function testAnUnregisteredAttributeIsRefusedByTheServer(): void
    {
        // The one rule the object cannot check locally: it would have to read the namespace's
        // registry.
        $this->expectExceptionMessageMatches('/no mapping defined for search attribute/');

        $this->startWorkflow('Plain', ['value' => 1], new WorkflowStartOptions(
            searchAttributes: SearchAttributes::none()->keyword('DurableNeverRegistered', 'x'),
        ));
    }

    private function firstWorkflowIdMatching(string $query): ?string
    {
        $req = new \Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setQuery($query);
        $req->setPageSize(1);

        try {
            $response = $this->client->ListWorkflowExecutions($req, [], ['timeout' => 10_000_000]);
        } catch (\RuntimeException) {
            return null;
        }

        foreach ($response->getExecutions() as $execution) {
            return $execution->getExecution()?->getWorkflowId();
        }

        return null;
    }
}
