<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Http;

use Gplanchat\Bridge\Temporal\Http\JsonGatewayWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\Http\Psr18Http;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;

/**
 * The gateway over any PSR-18 client: the request the curl path builds, sent by someone else.
 */
final class JsonGatewayOverPsr18Test extends TestCase
{
    public function testAGetRouteCarriesItsFieldsInThePathAndTheQuery(): void
    {
        $http = new RecordingPsr18Client(new Response(200, [], '{"executionConfig":{}}'));
        $request = new DescribeWorkflowExecutionRequest([
            'namespace' => 'default',
            'execution' => new WorkflowExecution(['workflow_id' => 'order-1', 'run_id' => 'r1']),
        ]);

        $response = $this->client($http)->DescribeWorkflowExecution($request);

        self::assertInstanceOf(DescribeWorkflowExecutionResponse::class, $response);
        self::assertSame('GET', $http->sent?->getMethod());
        self::assertSame('http://127.0.0.1:7243/api/v1/namespaces/default/workflows/order-1', $http->sent->getUri()->getScheme() . '://' . $http->sent->getUri()->getAuthority() . $http->sent->getUri()->getPath());
        self::assertStringContainsString('execution.runId=r1', $http->sent->getUri()->getQuery());
        self::assertSame('application/json', $http->sent->getHeaderLine('accept'));
    }

    public function testAPostRouteCarriesItsJsonBodyAndTheMetadata(): void
    {
        $http = new RecordingPsr18Client(new Response(200, [], '{}'));
        $request = new TerminateWorkflowExecutionRequest([
            'namespace' => 'default',
            'workflow_execution' => new WorkflowExecution(['workflow_id' => 'order-1']),
            'reason' => 'test',
        ]);

        $this->client($http)->TerminateWorkflowExecution($request, ['x-trace' => ['1']]);

        self::assertSame('POST', $http->sent?->getMethod());
        self::assertSame($request->serializeToJsonString(), (string) $http->sent->getBody());
        self::assertSame('application/json', $http->sent->getHeaderLine('content-type'));
        self::assertSame('1', $http->sent->getHeaderLine('x-trace'));
    }

    public function testTheGatewaysErrorBodyCarriesTheGrpcCode(): void
    {
        $http = new RecordingPsr18Client(new Response(404, [], '{"code":5,"message":"workflow not found"}'));

        try {
            $this->client($http)->DescribeWorkflowExecution(new DescribeWorkflowExecutionRequest(['namespace' => 'default']));
            self::fail('The gateway answered NOT_FOUND.');
        } catch (\RuntimeException $e) {
            self::assertSame(5, $e->getCode());
            self::assertStringContainsString('workflow not found', $e->getMessage());
        }
    }

    public function testAClientExceptionIsUnavailable(): void
    {
        // PSR-18 carries no timeout of its own: a deadline is the client's configuration, and it
        // cannot be told apart from any other network failure here.
        $http = new RecordingPsr18Client(new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {});

        try {
            $this->client($http)->DescribeWorkflowExecution(new DescribeWorkflowExecutionRequest(['namespace' => 'default']));
            self::fail('The client failed.');
        } catch (\RuntimeException $e) {
            self::assertSame(14, $e->getCode());
        }
    }

    public function testTheFactoryHandsThePsr18ClientToTransportHttp(): void
    {
        $http = new RecordingPsr18Client(new Response(200, [], '{}'));
        $factory = new HttpFactory();

        \Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory::create(
            TemporalConnection::fromDsn('temporal+http://127.0.0.1'),
            jsonGateway: new Psr18Http($http, $factory, $factory),
        )->DescribeWorkflowExecution(new DescribeWorkflowExecutionRequest(['namespace' => 'default']));

        self::assertNotNull($http->sent, 'the gateway call went through the handed client, not curl');
    }

    private function client(RecordingPsr18Client $http): JsonGatewayWorkflowServiceClient
    {
        $factory = new HttpFactory();

        return new JsonGatewayWorkflowServiceClient(
            TemporalConnection::fromDsn('temporal+http://127.0.0.1'),
            new Psr18Http($http, $factory, $factory),
        );
    }
}

final class RecordingPsr18Client implements ClientInterface
{
    public ?RequestInterface $sent = null;

    public function __construct(private readonly ResponseInterface|ClientExceptionInterface $answer) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent = $request;
        if ($this->answer instanceof ClientExceptionInterface) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
