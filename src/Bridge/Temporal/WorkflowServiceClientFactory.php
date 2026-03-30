<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Grpc\ChannelCredentials;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

final class WorkflowServiceClientFactory
{
    public static function assertGrpcExtension(): void
    {
        if (!\extension_loaded('grpc')) {
            throw new \RuntimeException('PHP extension "grpc" is required for the Temporal journal bridge (gRPC client / worker).');
        }
    }

    public static function create(TemporalConnection $settings): WorkflowServiceClient
    {
        self::assertGrpcExtension();

        $credentials = $settings->tls
            ? ChannelCredentials::createSsl()
            : ChannelCredentials::createInsecure();

        return new WorkflowServiceClient($settings->target, ['credentials' => $credentials]);
    }
}
