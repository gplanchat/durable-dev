<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityHeartbeatSender;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatRequest;
use Temporal\Api\Workflowservice\V1\RecordActivityTaskHeartbeatResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedResponse;

/**
 * One sender serves every task the worker takes, and the activities hold it too. Each task must
 * start from its own token and a clean cancellation flag, whether it declares a heartbeat timeout
 * or not: otherwise a task inherits the previous one's cancellation and cancels itself (#510).
 */
final class TemporalActivityWorkerHeartbeatTokenTest extends TestCase
{
    public function testATaskWithoutAHeartbeatTimeoutDoesNotInheritThePreviousTasksTokenOrCancellation(): void
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('PollActivityTaskQueue')->willReturnOnConsecutiveCalls(
            self::task('token-A', 'act-A', ActivityTimeouts::none()->withHeartbeat(Duration::seconds(5))),
            self::task('token-B', 'act-B', ActivityTimeouts::none()),
        );
        $sentUnder = [];
        $client->method('RecordActivityTaskHeartbeat')->willReturnCallback(
            static function (RecordActivityTaskHeartbeatRequest $request) use (&$sentUnder): RecordActivityTaskHeartbeatResponse {
                $sentUnder[] = $request->getTaskToken();

                // Task A is cancelled on the cluster; task B is not.
                return new RecordActivityTaskHeartbeatResponse(['cancel_requested' => 'token-A' === $request->getTaskToken()]);
            },
        );
        $client->method('RespondActivityTaskCompleted')->willReturn(new RespondActivityTaskCompletedResponse());

        $connection = new TemporalConnection('localhost:7233', 'default');
        $rpc = new WorkflowServiceActivityRpc($client);
        $sender = new TemporalActivityHeartbeatSender($rpc, $connection);
        $seenCancelled = [];
        $activities = new RegistryActivityExecutor();
        $activities->register('beat', static function () use ($sender, &$seenCancelled): string {
            $sender->sendHeartbeat();
            $seenCancelled[] = $sender->isCancellationRequested();

            return 'done';
        });
        $store = new InMemoryEventStore();
        $worker = new TemporalActivityWorker(
            $rpc,
            $connection,
            new ActivityMessageProcessor($store, new NoopActivityTransport(), $activities, new NullWorkflowResumeDispatcher(), $sender),
            $store,
            $sender,
        );

        $worker->pollOnce();
        $worker->pollOnce();

        self::assertSame(['token-A', 'token-B'], $sentUnder, 'each task heartbeats under its own token');
        self::assertSame([true, false], $seenCancelled, 'task B is not cancelled by task A');
    }

    private static function task(string $token, string $activityId, ActivityTimeouts $timeouts): PollActivityTaskQueueResponse
    {
        $payloads = new Payloads();
        $payloads->setPayloads([JsonPlainPayload::encode([
            'executionId' => 'exec-1',
            'activityId' => $activityId,
            'activityName' => 'beat',
            'metadata' => ActivityOptions::default()->withTimeouts($timeouts)->toMetadata(),
        ])]);

        $poll = new PollActivityTaskQueueResponse();
        $poll->setTaskToken($token);
        $poll->setInput($payloads);
        $poll->setAttempt(1);

        return $poll;
    }
}
