<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Grpc\UnaryCall;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskFailedResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * The worker must report an activity failure as non-retryable when the failed
 * exception type is listed in the activity's nonRetryableExceptions — otherwise
 * a bad-credential / rejected-payload failure would be retried forever by the
 * Temporal server instead of failing the workflow.
 *
 * @requires extension grpc
 */
final class TemporalActivityWorkerNonRetryableTest extends TestCase
{
    private WorkflowServiceClient $grpcClient;
    private InMemoryEventStore $eventStore;

    protected function setUp(): void
    {
        $this->grpcClient = $this->createMock(WorkflowServiceClient::class);
        $this->eventStore = new InMemoryEventStore();
    }

    public function testFailureWithNonRetryableTypeIsReportedNonRetryable(): void
    {
        $this->eventStore->append(new ActivityFailed('exec-nr', 'act-nr', 'App\\BusinessException', 'boom'));
        $metadata = (new ActivityOptions(nonRetryableExceptions: ['App\\BusinessException']))->toMetadata();
        $this->grpcClient->method('PollActivityTaskQueue')
            ->willReturn($this->unaryCall($this->pollFor('exec-nr', 'act-nr', $metadata), \Grpc\STATUS_OK));

        $captured = $this->captureFailedRequest();
        $this->makeWorker()->pollOnce();

        self::assertNotNull($captured->request);
        self::assertTrue($captured->request->getFailure()?->getApplicationFailureInfo()?->getNonRetryable());
    }

    public function testFailureWithoutNonRetryableTypeStaysRetryable(): void
    {
        // A retryable (system) exception must keep nonRetryable=false so the
        // server's retry policy still applies.
        $this->eventStore->append(new ActivityFailed('exec-r', 'act-r', 'App\\ServiceUnavailableException', 'boom'));
        $metadata = (new ActivityOptions(nonRetryableExceptions: ['App\\BusinessException']))->toMetadata();
        $this->grpcClient->method('PollActivityTaskQueue')
            ->willReturn($this->unaryCall($this->pollFor('exec-r', 'act-r', $metadata), \Grpc\STATUS_OK));

        $captured = $this->captureFailedRequest();
        $this->makeWorker()->pollOnce();

        self::assertNotNull($captured->request);
        self::assertFalse($captured->request->getFailure()?->getApplicationFailureInfo()?->getNonRetryable());
    }

    // -------------------------------------------------------------------------

    private function captureFailedRequest(): object
    {
        $box = new class {
            public ?RespondActivityTaskFailedRequest $request = null;
        };
        $this->grpcClient->method('RespondActivityTaskFailed')
            ->willReturnCallback(function (RespondActivityTaskFailedRequest $req) use ($box): UnaryCall {
                $box->request = $req;

                return $this->unaryCall(new RespondActivityTaskFailedResponse(), \Grpc\STATUS_OK);
            });

        return $box;
    }

    private function makeWorker(): TemporalActivityWorker
    {
        $processor = new ActivityMessageProcessor(
            $this->eventStore,
            new NoopActivityTransport(),
            new RegistryActivityExecutor(),
            new NullWorkflowResumeDispatcher(),
            $this->createMock(ActivityHeartbeatSenderInterface::class),
        );

        return new TemporalActivityWorker(
            new WorkflowServiceActivityRpc($this->grpcClient),
            new TemporalConnection('localhost:7233', 'test-namespace'),
            $processor,
            $this->eventStore,
            $this->createMock(ActivityHeartbeatSenderInterface::class),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function pollFor(string $executionId, string $activityId, array $metadata = []): PollActivityTaskQueueResponse
    {
        $payloads = new Payloads();
        $payloads->setPayloads([JsonPlainPayload::encode([
            'executionId' => $executionId,
            'activityId' => $activityId,
            'activityName' => 'sso_delete_user',
            'metadata' => $metadata,
        ])]);

        $poll = new PollActivityTaskQueueResponse();
        $poll->setTaskToken('task-token');
        $poll->setInput($payloads);

        return $poll;
    }

    private function unaryCall(?object $response, int $code): UnaryCall
    {
        $status = new \stdClass();
        $status->code = $code;
        $status->details = 'gRPC failure';

        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn([$response, $status]);

        return $call;
    }
}
