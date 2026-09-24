<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Http;

use Gplanchat\Bridge\Temporal\Http\CurlGrpcTransport;
use Gplanchat\Bridge\Temporal\Http\GrpcWire;
use Gplanchat\Bridge\Temporal\Http\GuzzleGrpcTransport;
use Gplanchat\Bridge\Temporal\Http\JsonGatewayWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\Http\Psr18Http;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;

/**
 * What `ca`, `cert`, `key` and `api_key` become on each wire (#353).
 */
final class TlsAndApiKeyWiringTest extends TestCase
{
    private const METHOD = '/temporal.api.workflowservice.v1.WorkflowService/DescribeWorkflowExecution';

    public function testGuzzleSendsTheApiKeyAndTheTlsFiles(): void
    {
        $seen = null;
        $guzzle = new Client(['handler' => static function (RequestInterface $request, array $options) use (&$seen): PromiseInterface {
            $seen = [$request, $options];

            return Create::promiseFor(new Response(200, ['grpc-status' => '0'], GrpcWire::frame('')));
        }]);

        (new GuzzleGrpcTransport(self::connection(), $guzzle))
            ->unary(self::METHOD, new DescribeWorkflowExecutionRequest(), DescribeWorkflowExecutionResponse::class, [], null);

        [$sent, $options] = $seen;
        self::assertSame('Bearer k3y', $sent->getHeaderLine('authorization'));
        self::assertSame('ns.acct', $sent->getHeaderLine('temporal-namespace'));
        self::assertSame(__FILE__, $options['verify']);
        self::assertSame(__FILE__, $options['cert']);
        self::assertSame(__FILE__, $options['ssl_key']);
    }

    public function testCurlGetsTheTlsFiles(): void
    {
        self::assertSame(
            [\CURLOPT_CAINFO => __FILE__, \CURLOPT_SSLCERT => __FILE__, \CURLOPT_SSLKEY => __FILE__],
            CurlGrpcTransport::tlsOptions(self::connection()),
        );
        self::assertSame([], CurlGrpcTransport::tlsOptions(TemporalConnection::fromDsn('temporal+tls://127.0.0.1')));
    }

    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('grpc')]
    public function testTheExtensionReadsThePemFiles(): void
    {
        self::assertInstanceOf(\Grpc\ChannelCredentials::class, WorkflowServiceClientFactory::channelOptions(self::connection())['credentials']);
    }

    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('grpc')]
    public function testTheExtensionSendsTheApiKeyWithTheCallersMetadata(): void
    {
        // The stub's own call path, down to the call it starts; only the network is left out.
        $invoker = new class implements \Grpc\CallInvoker {
            /** @var array<string, list<string>>|null */
            public ?array $metadata = null;

            public function createChannelFactory(mixed $hostname, mixed $opts): mixed
            {
                return null;
            }

            public function UnaryCall(mixed $channel, mixed $method, mixed $deserialize, mixed $options): mixed
            {
                $invoker = $this;

                return new class ($invoker) {
                    public function __construct(private object $invoker) {}

                    /**
                     * @param array<string, list<string>> $metadata
                     * @param array<string, mixed>        $options
                     */
                    public function start(mixed $argument, array $metadata, array $options): void
                    {
                        $this->invoker->metadata = $metadata;
                    }
                };
            }

            public function ClientStreamingCall(mixed $channel, mixed $method, mixed $deserialize, mixed $options): mixed
            {
                return null;
            }

            public function ServerStreamingCall(mixed $channel, mixed $method, mixed $deserialize, mixed $options): mixed
            {
                return null;
            }

            public function BidiStreamingCall(mixed $channel, mixed $method, mixed $deserialize, mixed $options): mixed
            {
                return null;
            }
        };
        $stub = new class ('127.0.0.1:7233', ['grpc_call_invoker' => $invoker] + WorkflowServiceClientFactory::channelOptions(self::connection())) extends \Grpc\BaseStub {
            public function send(): void
            {
                /** @psalm-suppress InvalidArgument (as in ExtGrpcTransport: the extension reads a [class, method] pair) */
                $this->_simpleRequest(self::class, new DescribeWorkflowExecutionRequest(), [DescribeWorkflowExecutionResponse::class, 'decode'], ['x-trace' => ['1']]);
            }
        };

        $stub->send();

        self::assertSame(['x-trace' => ['1'], 'authorization' => ['Bearer k3y'], 'temporal-namespace' => ['ns.acct']], $invoker->metadata);
    }

    public function testTheJsonGatewaySendsTheApiKey(): void
    {
        $http = self::psr18();
        $connection = TemporalConnection::fromDsn('temporal+https://127.0.0.1?namespace=ns.acct&api_key=k3y');
        $factory = new HttpFactory();

        (new JsonGatewayWorkflowServiceClient($connection, new Psr18Http($http, $factory, $factory)))->DescribeWorkflowExecution(
            new DescribeWorkflowExecutionRequest(['namespace' => 'ns.acct', 'execution' => new WorkflowExecution(['workflow_id' => 'w'])]),
        );

        self::assertSame('Bearer k3y', $http->sent?->getHeaderLine('authorization'));
        self::assertSame('ns.acct', $http->sent->getHeaderLine('temporal-namespace'));
    }

    public function testTheApiKeyStaysOutOfTheTraceOfAFailedGatewayCall(): void
    {
        // php.ini-development keeps the arguments in traces, and a debug error page prints them.
        $previous = ini_set('zend.exception_ignore_args', '0');
        $factory = new HttpFactory();
        $refusing = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('refused') extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
            }
        };
        $request = new DescribeWorkflowExecutionRequest(['namespace' => 'default', 'execution' => new WorkflowExecution(['workflow_id' => 'w'])]);
        $overCurl = TemporalConnection::fromDsn('temporal+https://127.0.0.1:1?api_key=S3CRETK3Y');
        $overPsr18 = TemporalConnection::fromDsn('temporal+https://127.0.0.1:1?api_key=S3CRETK3Y');

        try {
            foreach ([new JsonGatewayWorkflowServiceClient($overCurl), new JsonGatewayWorkflowServiceClient($overPsr18, new Psr18Http($refusing, $factory, $factory))] as $client) {
                try {
                    $client->DescribeWorkflowExecution($request);
                    self::fail('the call must fail');
                } catch (\RuntimeException $e) {
                    self::assertSame(GrpcWire::UNAVAILABLE, $e->getCode());
                    self::assertStringNotContainsString('S3CRETK3Y', print_r($e->getTrace(), true));
                }
            }

            try {
                (new CurlGrpcTransport($overCurl))->unary(self::METHOD, $request, DescribeWorkflowExecutionResponse::class, [], 1000);
                self::fail('the call must fail');
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString('S3CRETK3Y', print_r($e->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testAHandedPsr18ClientCannotTakeTheTlsFiles(): void
    {
        // Its TLS is its own configuration: accepting the files would ignore them.
        $factory = new HttpFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#PSR-18#');

        WorkflowServiceClientFactory::create(
            TemporalConnection::fromDsn('temporal+https://127.0.0.1?ca=' . rawurlencode(__FILE__)),
            jsonGateway: new Psr18Http(self::psr18(), $factory, $factory),
        );
    }

    private static function psr18(): HeaderCapturingPsr18Client
    {
        return new HeaderCapturingPsr18Client();
    }

    private static function connection(): TemporalConnection
    {
        $file = rawurlencode(__FILE__);

        return TemporalConnection::fromDsn("temporal+tls://127.0.0.1:7233?namespace=ns.acct&ca={$file}&cert={$file}&key={$file}&api_key=k3y");
    }
}

final class HeaderCapturingPsr18Client implements ClientInterface
{
    public ?RequestInterface $sent = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent = $request;

        return new Response(200, [], '{}');
    }
}
