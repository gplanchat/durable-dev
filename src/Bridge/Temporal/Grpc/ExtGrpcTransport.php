<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Grpc\BaseStub;
use Grpc\UnaryCall;

/**
 * gRPC over the extension, for any service: {@see BaseStub::_simpleRequest()} is what every
 * generated stub method calls, with its own path and response class.
 */
final class ExtGrpcTransport extends BaseStub implements GrpcTransport
{
    public function __construct(TemporalConnection $connection)
    {
        WorkflowServiceClientFactory::assertGrpcExtension();

        parent::__construct($connection->target, WorkflowServiceClientFactory::channelOptions($connection));
    }

    public function unary(string $method, Message $request, string $responseClass, array $metadata, ?int $timeoutMs): Message
    {
        /**
         * @var UnaryCall<Message> $call
         *
         * @psalm-suppress InvalidArgument — grpc/grpc documents $deserialize as a callable, but
         * AbstractCall::_deserializeResponse() reads it as [class, method] and instantiates the class:
         * the generated stubs pass exactly this pair.
         */
        $call = $this->_simpleRequest($method, $request, [$responseClass, 'decode'], $metadata, self::callOptions($timeoutMs));
        $response = GrpcUnary::wait($call);
        if (!$response instanceof $responseClass) {
            throw new \RuntimeException(\sprintf('Unexpected %s response type: %s.', $method, $response::class));
        }

        return $response;
    }

    /**
     * The extension reads a deadline in microseconds, and only this key of the options is
     * carried: the transport contract has no room for the rest.
     *
     * @return array{timeout?: int}
     */
    public static function callOptions(?int $timeoutMs): array
    {
        return null === $timeoutMs ? [] : ['timeout' => $timeoutMs * 1000];
    }
}
