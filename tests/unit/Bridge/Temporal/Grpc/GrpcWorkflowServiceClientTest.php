<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Grpc;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\Grpc\GrpcTransport;
use Gplanchat\Bridge\Temporal\Grpc\GrpcWorkflowServiceClient;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedResponse;

/**
 * The client is the 38 RPC methods and nothing else: how the bytes travel is the transport's.
 */
final class GrpcWorkflowServiceClientTest extends TestCase
{
    public function testTheCallReachesTheTransportWithItsFullMethodPath(): void
    {
        $transport = new RecordingTransport(new DescribeWorkflowExecutionResponse());
        $request = new DescribeWorkflowExecutionRequest(['namespace' => 'default']);

        $response = (new GrpcWorkflowServiceClient($transport))
            ->DescribeWorkflowExecution($request, ['x-trace' => ['1']], ['timeout' => 2_500_000]);

        self::assertSame($transport->response, $response);
        self::assertSame('/temporal.api.workflowservice.v1.WorkflowService/DescribeWorkflowExecution', $transport->method);
        self::assertSame($request, $transport->request);
        self::assertSame(DescribeWorkflowExecutionResponse::class, $transport->responseClass);
        self::assertSame(['x-trace' => ['1']], $transport->metadata);
        self::assertSame(2500, $transport->timeoutMs, 'the ext-grpc microseconds become milliseconds');
    }

    public function testNoTimeoutOptionMeansNoDeadline(): void
    {
        $transport = new RecordingTransport(new RespondActivityTaskCompletedResponse());

        (new GrpcWorkflowServiceClient($transport))->RespondActivityTaskCompleted(new RespondActivityTaskCompletedRequest());

        self::assertSame('/temporal.api.workflowservice.v1.WorkflowService/RespondActivityTaskCompleted', $transport->method);
        self::assertNull($transport->timeoutMs);
    }

    public function testAFailedCallSurfacesUnchanged(): void
    {
        // NOT_FOUND on RespondActivityTask* is benign for the worker, which reads the code: the
        // client must not wrap or rename what the transport throws.
        $failure = new \RuntimeException('Temporal gRPC error [5]: not found', 5);

        try {
            (new GrpcWorkflowServiceClient(new RecordingTransport($failure)))->RespondActivityTaskCompleted(new RespondActivityTaskCompletedRequest());
            self::fail('The transport failed.');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }
    }
}

final class RecordingTransport implements GrpcTransport
{
    public ?string $method = null;

    public ?Message $request = null;

    public ?string $responseClass = null;

    /** @var array<string, list<string>>|null */
    public ?array $metadata = null;

    public ?int $timeoutMs = -1;

    public function __construct(public readonly Message|\RuntimeException $response) {}

    public function unary(string $method, Message $request, string $responseClass, array $metadata, ?int $timeoutMs): Message
    {
        [$this->method, $this->request, $this->responseClass, $this->metadata, $this->timeoutMs] = [$method, $request, $responseClass, $metadata, $timeoutMs];
        if ($this->response instanceof \RuntimeException) {
            throw $this->response;
        }

        return $this->response;
    }
}
