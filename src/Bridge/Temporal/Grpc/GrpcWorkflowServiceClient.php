<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\AbstractWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\Http\GrpcWire;

/**
 * The Temporal WorkflowService over gRPC. The RPC methods are the traits'; how each call travels
 * is the {@see GrpcTransport}'s.
 */
final class GrpcWorkflowServiceClient extends AbstractWorkflowServiceClient
{
    private const SERVICE_PATH = '/temporal.api.workflowservice.v1.WorkflowService/';

    public function __construct(private readonly GrpcTransport $transport) {}

    protected function call(string $rpc, Message $request, string $responseClass, array $metadata, array $options): Message
    {
        $timeoutMs = GrpcWire::timeoutMs($options);

        /** @var array<string, list<string>> $metadata */
        return $this->transport->unary(self::SERVICE_PATH . $rpc, $request, $responseClass, $metadata, $timeoutMs > 0 ? $timeoutMs : null);
    }
}
