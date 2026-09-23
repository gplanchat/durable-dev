<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Google\Protobuf\Internal\Message;

/**
 * How one gRPC unary call travels: the extension, curl over HTTP/2, or any HTTP/2 client that
 * exposes trailers. Nothing here is Temporal's: the method is the full gRPC path.
 *
 * @see https://github.com/gplanchat/durable-dev/issues/425
 */
interface GrpcTransport
{
    /**
     * @template T of Message
     *
     * @param string                       $method        the full path, e.g. "/temporal.api.workflowservice.v1.WorkflowService/StartWorkflowExecution"
     * @param class-string<T>              $responseClass
     * @param array<string, list<string>>  $metadata
     * @param int|null                     $timeoutMs     the call's deadline; null means none
     *
     * @return T
     *
     * @throws \RuntimeException on any status but OK, with the gRPC status code as its code
     */
    public function unary(string $method, Message $request, string $responseClass, array $metadata, ?int $timeoutMs): Message;
}
