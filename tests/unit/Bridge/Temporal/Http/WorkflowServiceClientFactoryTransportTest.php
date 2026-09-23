<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Http;

use Gplanchat\Bridge\Temporal\Grpc\ExtGrpcTransport;
use Gplanchat\Bridge\Temporal\Grpc\GrpcWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\Http\CurlGrpcTransport;
use Gplanchat\Bridge\Temporal\Http\JsonGatewayWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use PHPUnit\Framework\TestCase;

final class WorkflowServiceClientFactoryTransportTest extends TestCase
{
    public function testTheDsnTransportPicksTheClient(): void
    {
        // gRPC is one client over a transport; only the JSON gateway, which is not gRPC, is a
        // client of its own.
        self::assertInstanceOf(
            GrpcWorkflowServiceClient::class,
            WorkflowServiceClientFactory::create(TemporalConnection::fromDsn('temporal://127.0.0.1:7233?transport=grpc-curl')),
        );
        self::assertInstanceOf(
            CurlGrpcTransport::class,
            WorkflowServiceClientFactory::createTransport(TemporalConnection::fromDsn('temporal://127.0.0.1:7233?transport=grpc-curl')),
        );
        self::assertInstanceOf(
            JsonGatewayWorkflowServiceClient::class,
            WorkflowServiceClientFactory::create(TemporalConnection::fromDsn('temporal://127.0.0.1?transport=http')),
        );
    }

    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('grpc')]
    public function testTransportGrpcIsTheExtension(): void
    {
        self::assertInstanceOf(
            ExtGrpcTransport::class,
            WorkflowServiceClientFactory::createTransport(TemporalConnection::fromDsn('temporal://127.0.0.1:7233?transport=grpc')),
        );
    }

    public function testTheJsonGatewayHasNoGrpcTransportToHandOut(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/JSON gateway/');

        WorkflowServiceClientFactory::createTransport(TemporalConnection::fromDsn('temporal+http://127.0.0.1'));
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
