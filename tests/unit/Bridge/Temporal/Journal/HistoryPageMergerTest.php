<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Journal;

use Gplanchat\Bridge\Temporal\Journal\HistoryPageMerger;
use Grpc\UnaryCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * @internal
 */
#[CoversClass(HistoryPageMerger::class)]
final class HistoryPageMergerTest extends TestCase
{
    #[Test]
    public function fullHistoryForExecutionFollowsNextPageTokenAndMergesChunks(): void
    {
        $execution = new WorkflowExecution(['workflow_id' => 'wf-journal', 'run_id' => 'run-1']);

        $ev1 = new HistoryEvent([
            'event_id' => 1,
            'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED,
        ]);
        $ev2 = new HistoryEvent([
            'event_id' => 2,
            'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED,
        ]);

        $calls = 0;
        $test = $this;
        $client = $this->createMock(WorkflowServiceClient::class);
        $client->method('GetWorkflowExecutionHistory')
            ->willReturnCallback(function () use ($test, &$calls, $ev1, $ev2) {
                ++$calls;
                $callNum = $calls;

                $call = $test->getMockBuilder(UnaryCall::class)
                    ->disableOriginalConstructor()
                    ->onlyMethods(['wait'])
                    ->getMock()
                ;
                $call->method('wait')->willReturnCallback(function () use ($callNum, $ev1, $ev2) {
                    $status = new \stdClass();
                    $status->code = 0;
                    $status->details = '';

                    $response = new GetWorkflowExecutionHistoryResponse();
                    if (1 === $callNum) {
                        $response->setHistory(new History(['events' => [$ev1]]));
                        $response->setNextPageToken('page-2-token');
                    } else {
                        $response->setHistory(new History(['events' => [$ev2]]));
                        $response->setNextPageToken('');
                    }

                    return [$response, $status];
                });

                return $call;
            })
        ;

        $merger = new HistoryPageMerger($client, 'default');
        $merged = $merger->fullHistoryForExecution($execution);

        self::assertSame(2, $calls, 'First call + one continuation for next_page_token.');
        self::assertCount(2, iterator_to_array($merged->getEvents()));
        self::assertSame(1, $merged->getEvents()[0]->getEventId());
        self::assertSame(2, $merged->getEvents()[1]->getEventId());
    }

    #[Test]
    public function fullHistoryForExecutionReturnsFirstPageWhenNoNextPageToken(): void
    {
        $execution = new WorkflowExecution(['workflow_id' => 'wf', 'run_id' => 'r1']);
        $ev1 = new HistoryEvent([
            'event_id' => 10,
            'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED,
        ]);

        $test = $this;
        $client = $this->createMock(WorkflowServiceClient::class);
        $client->method('GetWorkflowExecutionHistory')
            ->willReturnCallback(function () use ($test, $ev1) {
                $call = $test->getMockBuilder(UnaryCall::class)
                    ->disableOriginalConstructor()
                    ->onlyMethods(['wait'])
                    ->getMock()
                ;
                $call->method('wait')->willReturnCallback(function () use ($ev1) {
                    $status = new \stdClass();
                    $status->code = 0;
                    $status->details = '';
                    $response = new GetWorkflowExecutionHistoryResponse();
                    $response->setHistory(new History(['events' => [$ev1]]));
                    $response->setNextPageToken('');

                    return [$response, $status];
                });

                return $call;
            })
        ;

        $merger = new HistoryPageMerger($client, 'ns');
        $history = $merger->fullHistoryForExecution($execution);

        self::assertCount(1, iterator_to_array($history->getEvents()));
    }

    #[Test]
    public function fullHistoryForExecutionReturnsEmptyHistoryWhenGrpcNotFound(): void
    {
        $execution = new WorkflowExecution(['workflow_id' => 'missing', 'run_id' => 'x']);

        $test = $this;
        $client = $this->createMock(WorkflowServiceClient::class);
        $client->method('GetWorkflowExecutionHistory')
            ->willReturnCallback(function () use ($test) {
                $call = $test->getMockBuilder(UnaryCall::class)
                    ->disableOriginalConstructor()
                    ->onlyMethods(['wait'])
                    ->getMock()
                ;
                $call->method('wait')->willReturnCallback(static function () {
                    $status = new \stdClass();
                    $status->code = 5;
                    $status->details = 'not found';

                    return [null, $status];
                });

                return $call;
            })
        ;

        $merger = new HistoryPageMerger($client, 'ns');
        $history = $merger->fullHistoryForExecution($execution);

        self::assertCount(0, iterator_to_array($history->getEvents()));
    }

    #[Test]
    public function fullHistoryFromPollFollowsNextPageTokenWhenWorkflowExecutionPresent(): void
    {
        $exec = new WorkflowExecution(['workflow_id' => 'poll-wf', 'run_id' => 'poll-run']);
        $ev1 = new HistoryEvent(['event_id' => 1, 'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED]);
        $ev2 = new HistoryEvent(['event_id' => 2, 'event_type' => EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED]);

        $calls = 0;
        $test = $this;
        $client = $this->createMock(WorkflowServiceClient::class);
        $client->method('GetWorkflowExecutionHistory')
            ->willReturnCallback(function () use ($test, &$calls, $ev1, $ev2) {
                ++$calls;
                $callNum = $calls;

                $call = $test->getMockBuilder(UnaryCall::class)
                    ->disableOriginalConstructor()
                    ->onlyMethods(['wait'])
                    ->getMock()
                ;
                $call->method('wait')->willReturnCallback(function () use ($callNum, $ev1, $ev2) {
                    $status = new \stdClass();
                    $status->code = 0;
                    $status->details = '';
                    $response = new GetWorkflowExecutionHistoryResponse();
                    if (1 === $callNum) {
                        $response->setHistory(new History(['events' => [$ev1]]));
                        $response->setNextPageToken('');
                    } else {
                        $response->setHistory(new History(['events' => [$ev2]]));
                        $response->setNextPageToken('');
                    }

                    return [$response, $status];
                });

                return $call;
            })
        ;

        $poll = new PollWorkflowTaskQueueResponse();
        $poll->setWorkflowExecution($exec);
        $poll->setHistory(new History(['events' => [$ev1]]));
        $poll->setNextPageToken('cont');

        $merger = new HistoryPageMerger($client, 'default');
        $merged = $merger->fullHistoryFromPoll($poll);

        self::assertSame(1, $calls, 'appendPages issues one GetWorkflowExecutionHistory for next_page_token.');
        self::assertCount(2, iterator_to_array($merged->getEvents()));
    }
}
