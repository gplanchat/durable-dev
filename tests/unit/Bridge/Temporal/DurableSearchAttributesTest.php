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
        self::assertSame('App.Workflow.OrderWorkflow', DurableSearchAttributes::value('App\\Workflow\\OrderWorkflow'));
    }

    public function testTwoNamesNeverShareANormalizedForm(): void
    {
        // An alias is free text: `App.Order` and `App\Order` are two workflows, and stay two.
        $names = ['App\\Order', 'App.Order', 'App%2EOrder', 'App%5COrder', 'App%Order', 'App..Order', 'App\\\\Order'];

        $normalized = array_map(DurableSearchAttributes::value(...), $names);

        self::assertCount(\count($names), array_unique($normalized));
        foreach ($normalized as $value) {
            self::assertStringNotContainsString('\\', $value);
        }
    }

    public function testAValueKeepsUpTo255CharactersAsItIs(): void
    {
        self::assertSame(str_repeat('a', 255), DurableSearchAttributes::value(str_repeat('a', 255)));
    }

    public function testALongerValueBecomesAPrefixAndAHashOfTheWhole(): void
    {
        $long = DurableSearchAttributes::value(str_repeat('a', 256));

        self::assertSame(255, \strlen($long));
        self::assertStringStartsWith(str_repeat('a', 100), $long);
        self::assertSame($long, DurableSearchAttributes::value(str_repeat('a', 256)), 'stable, so the query finds it');
    }

    public function testTwoLongValuesNeverShareAForm(): void
    {
        // Same 300-character prefix, different tails; and a normalized name that grows past 255
        // only through its escapes.
        $values = [str_repeat('a', 300) . 'x', str_repeat('a', 300) . 'y', str_repeat('.', 100), str_repeat('%', 100)];

        $forms = array_map(DurableSearchAttributes::value(...), $values);

        self::assertCount(4, array_unique($forms));
        foreach ($forms as $form) {
            self::assertLessThanOrEqual(255, \strlen($form));
        }
    }

    public function testALongFormNeverCutsACharacterInHalf(): void
    {
        // The payload is JSON: half a UTF-8 character makes the start fail to encode.
        foreach (range(0, 3) as $shift) {
            $form = DurableSearchAttributes::value(str_repeat('a', $shift) . str_repeat("\u{4E2D}", 200));

            self::assertSame(1, preg_match('//u', $form), \sprintf('valid UTF-8 with a shift of %d', $shift));
        }
    }

    public function testALongFormIsNeverTheFormOfAShortValue(): void
    {
        // A normalized value only holds `%` as `%25` or `%2E`, and the long form's separator is
        // neither: no value of 255 characters or fewer can spell a long form.
        $long = DurableSearchAttributes::value(str_repeat('b', 400));

        self::assertNotSame($long, DurableSearchAttributes::value($long));
    }

    public function testNothingIsWrittenUnlessTheConnectionEnablesIt(): void
    {
        $callers = SearchAttributes::none()->keyword('CustomerId', 'c-7');

        self::assertSame($callers, DurableSearchAttributes::of(new TemporalConnection('localhost:7233', 'test'), 'exec-1', 'App\\OrderWorkflow', $callers));
    }

    public function testTheDsnFactoryTakesTheSwitch(): void
    {
        self::assertFalse(TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default')->searchAttributes);
        self::assertTrue(TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default', searchAttributes: true)->searchAttributes);
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
        $connection = TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0', searchAttributes: true);
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
        $buffer = new TemporalWorkflowCommandBuffer(self::enabled(), 'parent-1');
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
        $buffer = new TemporalWorkflowCommandBuffer(self::enabled(), 'exec-1');
        $buffer->continueAsNew('App\\NextWorkflow', []);

        self::assertSame(
            ['DurableWorkflowName' => 'App.NextWorkflow', 'DurableExecutionId' => 'exec-1'],
            self::values($buffer->peek()[0]->getContinueAsNewWorkflowExecutionCommandAttributes()?->getSearchAttributes()),
        );
    }

    private static function enabled(): TemporalConnection
    {
        return new TemporalConnection('localhost:7233', 'test', searchAttributes: true);
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
