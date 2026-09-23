<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Http;

use Gplanchat\Bridge\Temporal\Http\GrpcWire;
use Gplanchat\Bridge\Temporal\Http\GuzzleGrpcTransport;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;

/**
 * A Guzzle handler stands in for the server: it sees what the cURL handler would send, and hands
 * the trailers back through on_trailers, as the cURL handler does after the body.
 */
final class GuzzleGrpcTransportTest extends TestCase
{
    private const METHOD = '/temporal.api.workflowservice.v1.WorkflowService/DescribeWorkflowExecution';

    public function testAnOkCallIsAnHttp2PostOfTheFrameAndReadsTheBody(): void
    {
        $reply = new DescribeWorkflowExecutionResponse();
        $seen = null;
        $transport = $this->transport('temporal://127.0.0.1:7233', function (RequestInterface $request, array $options) use ($reply, &$seen): PromiseInterface {
            $seen = [$request, $options];

            return $this->reply($options, GrpcWire::frame($reply->serializeToString()), ['grpc-status' => ['0']]);
        });

        $request = new DescribeWorkflowExecutionRequest(['namespace' => 'default']);
        $response = $transport->unary(self::METHOD, $request, DescribeWorkflowExecutionResponse::class, ['x-trace' => ['1']], 2_500);

        self::assertInstanceOf(DescribeWorkflowExecutionResponse::class, $response);
        [$sent, $options] = $seen;
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('http://127.0.0.1:7233' . self::METHOD, (string) $sent->getUri());
        self::assertSame('2.0', $sent->getProtocolVersion());
        self::assertSame(GrpcWire::frame($request->serializeToString()), (string) $sent->getBody());
        self::assertSame('application/grpc', $sent->getHeaderLine('content-type'));
        self::assertSame('trailers', $sent->getHeaderLine('te'));
        self::assertSame('2500m', $sent->getHeaderLine('grpc-timeout'));
        self::assertSame('1', $sent->getHeaderLine('x-trace'));
        // h2c: the Go gRPC server accepts no HTTP/1.1 upgrade, so the version is forced raw.
        self::assertSame(\CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE, $options['curl'][\CURLOPT_HTTP_VERSION]);
    }

    public function testOverTlsHttp2IsNegotiated(): void
    {
        $seen = null;
        $transport = $this->transport('temporal+tls://temporal.example:7233', function (RequestInterface $request, array $options) use (&$seen): PromiseInterface {
            $seen = [$request, $options];

            return $this->reply($options, GrpcWire::frame(''), ['grpc-status' => ['0']]);
        });

        $transport->unary(self::METHOD, new DescribeWorkflowExecutionRequest(), DescribeWorkflowExecutionResponse::class, [], null);

        self::assertSame('https://temporal.example:7233' . self::METHOD, (string) $seen[0]->getUri());
        self::assertSame(\CURL_HTTP_VERSION_2_0, $seen[1]['curl'][\CURLOPT_HTTP_VERSION]);
        self::assertFalse($seen[0]->hasHeader('grpc-timeout'), 'no deadline, no header');
    }

    public function testAStatusInTheTrailersSurfacesAsTheExceptionCode(): void
    {
        $transport = $this->transport('temporal://127.0.0.1:7233', fn(RequestInterface $r, array $o): PromiseInterface => $this->reply($o, '', ['grpc-status' => ['5'], 'grpc-message' => ['workflow%20not%20found']]));

        try {
            $transport->unary(self::METHOD, new DescribeWorkflowExecutionRequest(), DescribeWorkflowExecutionResponse::class, [], null);
            self::fail('NOT_FOUND was in the trailers.');
        } catch (\RuntimeException $e) {
            self::assertSame(5, $e->getCode());
            self::assertStringContainsString('workflow not found', $e->getMessage());
        }
    }

    /**
     * @param callable(RequestInterface, array<string, mixed>): PromiseInterface $handler
     */
    private function transport(string $dsn, callable $handler): GuzzleGrpcTransport
    {
        return new GuzzleGrpcTransport(TemporalConnection::fromDsn($dsn), new Client(['handler' => $handler]));
    }

    /**
     * @param array<string, mixed>        $options
     * @param array<string, list<string>> $trailers
     */
    private function reply(array $options, string $body, array $trailers): PromiseInterface
    {
        $response = new Response(200, ['content-type' => 'application/grpc'], $body);
        ($options['on_trailers'])($trailers, $response);

        return Create::promiseFor($response);
    }
}
