<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\AbstractWorkflowServiceClient;
use Grpc\UnaryCall;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * The ext-grpc transport: the generated stub, waited on synchronously.
 */
final class GrpcWorkflowServiceClient extends AbstractWorkflowServiceClient
{
    public function __construct(private readonly WorkflowServiceClient $stub) {}

    protected function call(string $rpc, Message $request, string $responseClass, array $metadata, array $options): Message
    {
        /** @var UnaryCall $call */
        $call = $this->stub->{$rpc}($request, $metadata, $options);
        $response = GrpcUnary::wait($call);
        if (!$response instanceof $responseClass) {
            throw new \RuntimeException(\sprintf('Unexpected %s response type: %s.', $rpc, $response::class));
        }

        return $response;
    }
}
