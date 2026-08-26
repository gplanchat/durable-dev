<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\WorkflowStartOptions;
use Temporal\Api\Enums\V1\EventType;

/**
 * Les attributs de recherche n'atteignaient jamais le serveur : journalisés dans les métadonnées,
 * puis oubliés, aucune commande ne les posant.
 *
 * Prérequis du namespace de test :
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
        // C'est tout l'intérêt d'un attribut de recherche : retrouver l'exécution.
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
        // Seule règle que l'objet ne peut pas vérifier localement : il faudrait lire le registre
        // du namespace.
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

        [$response, $status] = $this->client->ListWorkflowExecutions($req, [], ['timeout' => 10_000_000])->wait();
        if (0 !== (int) ($status->code ?? -1) || !$response instanceof \Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsResponse) {
            return null;
        }

        foreach ($response->getExecutions() as $execution) {
            return $execution->getExecution()?->getWorkflowId();
        }

        return null;
    }
}
