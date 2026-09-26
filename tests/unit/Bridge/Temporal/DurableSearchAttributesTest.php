<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\DurableSearchAttributes;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\WorkflowStartOptions;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\SearchAttributes as TemporalSearchAttributes;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse;

/**
 * #558: every run Durable starts on Temporal carries its workflow name and its execution id as
 * Keyword search attributes, so the catalogue can filter on them. The server's query parser
 * matches no value that holds a backslash, hence a normalized name.
 */
final class DurableSearchAttributesTest extends TestCase
{
    public function testAClassNameReadsWithDots(): void
    {
        self::assertSame('App.Workflow.OrderWorkflow', DurableSearchAttributes::normalizedName('App\\Workflow\\OrderWorkflow'));
    }

    public function testTwoNamesNeverShareANormalizedForm(): void
    {
        // An alias is free text: `App.Order` and `App\Order` are two workflows, and stay two.
        $names = ['App\\Order', 'App.Order', 'App%2EOrder', 'App%5COrder', 'App%Order', 'App..Order', 'App\\\\Order'];

        $normalized = array_map(DurableSearchAttributes::normalizedName(...), $names);

        self::assertCount(\count($names), array_unique($normalized));
        foreach ($normalized as $value) {
            self::assertStringNotContainsString('\\', $value);
        }
    }

    public function testAQueryLiteralSurvivesQuotesAndBackslashes(): void
    {
        self::assertSame("'it\\'s'", DurableSearchAttributes::literal("it's"));
        self::assertSame("'a\\\\b'", DurableSearchAttributes::literal('a\\b'));
    }

    public function testAStartCarriesBothAttributesBesideTheCallersOwn(): void
    {
        $sent = null;
        $grpc = $this->createMock(WorkflowServiceClientInterface::class);
        $grpc->method('StartWorkflowExecution')->willReturnCallback(static function (StartWorkflowExecutionRequest $request) use (&$sent) {
            $sent = $request;

            return new StartWorkflowExecutionResponse();
        });
        $connection = TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0');
        $client = new WorkflowClient($grpc, $connection, new TemporalHistoryCursor($grpc, $connection), new WorkflowServiceExecutionRpc($grpc));

        $client->startAsync('App\\OrderWorkflow', [], 'exec-1', new WorkflowStartOptions(searchAttributes: SearchAttributes::none()->keyword('CustomerId', 'c-7')));

        self::assertInstanceOf(StartWorkflowExecutionRequest::class, $sent);
        self::assertSame(
            ['CustomerId' => 'c-7', 'DurableWorkflowName' => 'App.OrderWorkflow', 'DurableExecutionId' => 'exec-1'],
            self::values($sent->getSearchAttributes()),
        );
    }

    public function testAChildStartCarriesBothAttributes(): void
    {
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'parent-1');
        $buffer->scheduleChildWorkflow('child-1', 'App\\ChildWorkflow', [], new ChildWorkflowOptions());

        self::assertSame(
            ['DurableWorkflowName' => 'App.ChildWorkflow', 'DurableExecutionId' => 'child-1'],
            self::values($buffer->peek()[0]->getStartChildWorkflowExecutionCommandAttributes()?->getSearchAttributes()),
        );
    }

    public function testAContinueAsNewWritesBothAgain(): void
    {
        // The server carries no search attribute over to the next run, and a command that sets
        // some replaces them all (measured on Server 1.25.2, #558).
        $buffer = new TemporalWorkflowCommandBuffer(new TemporalConnection('localhost:7233', 'test'), 'exec-1');
        $buffer->continueAsNew('App\\NextWorkflow', []);

        self::assertSame(
            ['DurableWorkflowName' => 'App.NextWorkflow', 'DurableExecutionId' => 'exec-1'],
            self::values($buffer->peek()[0]->getContinueAsNewWorkflowExecutionCommandAttributes()?->getSearchAttributes()),
        );
    }

    /** @return array<string, mixed> */
    private static function values(?TemporalSearchAttributes $attributes): array
    {
        $values = [];
        foreach ($attributes?->getIndexedFields() ?? [] as $name => $payload) {
            $values[$name] = json_decode($payload->getData(), true);
        }

        return $values;
    }
}
