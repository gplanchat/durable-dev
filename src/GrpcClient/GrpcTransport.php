<?php

declare(strict_types=1);

namespace Gplanchat\GrpcClient;

use Google\Protobuf\Internal\Message;

/**
 * How one gRPC unary call travels: the extension, curl over HTTP/2, or any HTTP/2 client that
 * exposes trailers. The method is the full gRPC path.
 */
interface GrpcTransport
{
    /**
     * @template T of Message
     *
     * @param string                       $method        the full path, e.g. "/helloworld.Greeter/SayHello"
     * @param class-string<T>              $responseClass
     * @param array<string, list<string>>  $metadata
     * @param int|null                     $timeoutMs     the call's deadline; null means none
     *
     * @return T
     *
     * @throws GrpcException on any status but OK, with the gRPC status code as its code
     */
    public function unary(string $method, Message $request, string $responseClass, array $metadata, ?int $timeoutMs): Message;
}
