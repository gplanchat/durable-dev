<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\GrpcWorkflowServiceClient;
use Grpc\ChannelCredentials;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

final class WorkflowServiceClientFactory
{
    public static function assertGrpcExtension(): void
    {
        if (!\extension_loaded('grpc')) {
            throw new \RuntimeException('PHP extension "grpc" is required for transport=grpc; without it, install gplanchat/durable-bridge-temporal-http and use transport=grpc-curl.');
        }
    }

    public static function create(TemporalConnection $settings): WorkflowServiceClientInterface
    {
        if (TemporalConnection::TRANSPORT_GRPC === $settings->transport) {
            return new GrpcWorkflowServiceClient(self::createStub($settings));
        }

        // ponytail: the sibling package is named here rather than registered, because it is the
        // only other one; a registry earns its place with the third transport.
        $class = TemporalConnection::TRANSPORT_HTTP === $settings->transport
            ? 'Gplanchat\Bridge\TemporalHttp\JsonGatewayWorkflowServiceClient'
            : 'Gplanchat\Bridge\TemporalHttp\CurlGrpcWorkflowServiceClient';
        if (!class_exists($class)) {
            throw new \RuntimeException(\sprintf('transport=%s requires the gplanchat/durable-bridge-temporal-http package.', $settings->transport));
        }

        return new $class($settings);
    }

    public static function createStub(TemporalConnection $settings): WorkflowServiceClient
    {
        self::assertGrpcExtension();

        $credentials = $settings->tls
            ? ChannelCredentials::createSsl()
            : ChannelCredentials::createInsecure();

        return new WorkflowServiceClient($settings->target, ['credentials' => $credentials]);
    }
}
