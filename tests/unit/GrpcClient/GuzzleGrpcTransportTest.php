<?php

declare(strict_types=1);

namespace unit\Gplanchat\GrpcClient;

use Google\Protobuf\StringValue;
use Gplanchat\GrpcClient\GrpcEndpoint;
use Gplanchat\GrpcClient\GrpcException;
use Gplanchat\GrpcClient\GrpcWire;
use Gplanchat\GrpcClient\GuzzleGrpcTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle handler stands in for the server: it sees what the cURL handler would send, and hands
 * the trailers back through on_trailers, as the cURL handler does after the body.
 */
final class GuzzleGrpcTransportTest extends TestCase
{
    private const METHOD = '/helloworld.Greeter/SayHello';

    public function testAnOkCallIsAnHttp2PostOfTheFrameAndReadsTheBody(): void
    {
        $reply = new StringValue();
        $seen = null;
        $transport = $this->transport('127.0.0.1:7233', function (RequestInterface $request, array $options) use ($reply, &$seen): PromiseInterface {
            $seen = [$request, $options];

            return $this->reply($options, GrpcWire::frame($reply->serializeToString()), ['grpc-status' => ['0']]);
        });

        $request = new StringValue(['value' => 'default']);
        $response = $transport->unary(self::METHOD, $request, StringValue::class, ['x-trace' => ['1']], 2_500);

        self::assertInstanceOf(StringValue::class, $response);
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
        $transport = $this->transport('temporal.example:7233', function (RequestInterface $request, array $options) use (&$seen): PromiseInterface {
            $seen = [$request, $options];

            return $this->reply($options, GrpcWire::frame(''), ['grpc-status' => ['0']]);
        }, tls: true);

        $transport->unary(self::METHOD, new StringValue(), StringValue::class, [], null);

        self::assertSame('https://temporal.example:7233' . self::METHOD, (string) $seen[0]->getUri());
        self::assertSame(\CURL_HTTP_VERSION_2_0, $seen[1]['curl'][\CURLOPT_HTTP_VERSION]);
        self::assertFalse($seen[0]->hasHeader('grpc-timeout'), 'no deadline, no header');
    }

    public function testAStatusInTheTrailersSurfacesAsTheExceptionCode(): void
    {
        $transport = $this->transport('127.0.0.1:7233', fn(RequestInterface $r, array $o): PromiseInterface => $this->reply($o, '', ['grpc-status' => ['5'], 'grpc-message' => ['workflow%20not%20found']]));

        try {
            $transport->unary(self::METHOD, new StringValue(), StringValue::class, [], null);
            self::fail('NOT_FOUND was in the trailers.');
        } catch (GrpcException $e) {
            self::assertSame(5, $e->getCode());
            self::assertStringContainsString('workflow not found', $e->getMessage());
        }
    }

    public function testATimeoutIsDeadlineExceededAndARefusedConnectionIsUnavailable(): void
    {
        foreach ([28 => GrpcWire::DEADLINE_EXCEEDED, 7 => GrpcWire::UNAVAILABLE] as $errno => $code) {
            $transport = $this->transport('127.0.0.1:7233', static fn(RequestInterface $r): PromiseInterface => Create::rejectionFor(new ConnectException('cURL error ' . $errno, $r, null, ['errno' => $errno])));

            try {
                $transport->unary(self::METHOD, new StringValue(), StringValue::class, [], 100);
                self::fail('The transfer failed.');
            } catch (GrpcException $e) {
                self::assertSame($code, $e->getCode(), 'cURL errno ' . $errno);
            }
        }
    }

    /**
     * @param callable(RequestInterface, array<string, mixed>): PromiseInterface $handler
     */
    private function transport(string $target, callable $handler, bool $tls = false): GuzzleGrpcTransport
    {
        return new GuzzleGrpcTransport(new GrpcEndpoint($target, $tls), new Client(['handler' => $handler]));
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
