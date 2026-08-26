<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Plugin\Dashboard\TemporalEventsDashboardDataProvider;
use Grpc\UnaryCall;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsRequest;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsResponse;

/**
 * `TemporalConnection::$namespace` is a `WorkflowNamespace`, not a string, and protobuf setters
 * reject objects outright. Passing it raw threw inside the provider's `catch (\Throwable)`, so the
 * dashboard silently reported "Temporal is currently unreachable" forever instead of failing loud.
 */
#[RequiresPhpExtension('grpc')]
final class TemporalEventsDashboardDataProviderNamespaceTest extends TestCase
{
    public function testItSendsTheNamespaceAsAString(): void
    {
        $captured = null;

        $call = $this->createMock(UnaryCall::class);
        $status = new \stdClass();
        $status->code = \Grpc\STATUS_OK;
        $status->details = '';
        $call->method('wait')->willReturn([new ListWorkflowExecutionsResponse(), $status]);

        $client = $this->createMock(\Temporal\Api\Workflowservice\V1\WorkflowServiceClient::class);
        $client
            ->method('ListWorkflowExecutions')
            ->willReturnCallback(function (ListWorkflowExecutionsRequest $request) use (&$captured, $call): UnaryCall {
                $captured = $request;

                return $call;
            })
        ;

        $provider = new TemporalEventsDashboardDataProvider(
            $client,
            new TemporalConnection('localhost:7233', 'durable-test'),
        );

        $page = $provider->provideRunsPage();

        self::assertInstanceOf(ListWorkflowExecutionsRequest::class, $captured);
        self::assertSame('durable-test', $captured->getNamespace());
        self::assertTrue($page['temporal']['connected']);
        self::assertSame('durable-test', $page['temporal']['namespace']);
    }
}
