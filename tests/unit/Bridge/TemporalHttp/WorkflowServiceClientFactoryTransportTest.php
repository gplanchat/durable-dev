<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\TemporalHttp;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\TemporalHttp\CurlGrpcWorkflowServiceClient;
use Gplanchat\Bridge\TemporalHttp\JsonGatewayWorkflowServiceClient;
use PHPUnit\Framework\TestCase;

final class WorkflowServiceClientFactoryTransportTest extends TestCase
{
    public function testTheDsnTransportPicksTheClient(): void
    {
        self::assertInstanceOf(
            CurlGrpcWorkflowServiceClient::class,
            WorkflowServiceClientFactory::create(TemporalConnection::fromDsn('temporal://127.0.0.1:7233?transport=grpc-curl')),
        );
        self::assertInstanceOf(
            JsonGatewayWorkflowServiceClient::class,
            WorkflowServiceClientFactory::create(TemporalConnection::fromDsn('temporal://127.0.0.1?transport=http')),
        );
    }

    public function testTheJsonGatewayRefusesAWorkerRpcWithUnimplemented(): void
    {
        $client = new JsonGatewayWorkflowServiceClient(new TemporalConnection('127.0.0.1:1', 'default', transport: TemporalConnection::TRANSPORT_HTTP));

        try {
            $client->PollWorkflowTaskQueue(new \Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest());
            self::fail('A poll has no HTTP route and must not reach the network.');
        } catch (\RuntimeException $e) {
            self::assertSame(12, $e->getCode());
            self::assertStringContainsString('grpc-curl', $e->getMessage());
        }
    }
}
