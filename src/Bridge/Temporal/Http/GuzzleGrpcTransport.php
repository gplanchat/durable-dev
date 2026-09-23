<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Http;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\Grpc\GrpcTransport;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * gRPC over Guzzle, the same wire as {@see CurlGrpcTransport}: one HTTP/2 POST per unary call,
 * the frame in the body, the status in the trailers. Needs Guzzle 7.14 or newer for on_trailers,
 * and its cURL handler, the only one that observes trailers.
 *
 * h2c needs HTTP/2 prior knowledge: Guzzle's own way, `multiplex: require_*`, wants libcurl 8.14,
 * so the version is set raw instead — Guzzle applies raw cURL options after its own choice.
 */
final class GuzzleGrpcTransport implements GrpcTransport
{
    public function __construct(
        private readonly TemporalConnection $connection,
        private readonly ClientInterface $client,
    ) {}

    public function unary(string $method, Message $request, string $responseClass, array $metadata, ?int $timeoutMs): Message
    {
        $headers = ['content-type' => 'application/grpc', 'te' => 'trailers', 'user-agent' => 'durable-bridge-temporal/php'] + $metadata;
        if (null !== $timeoutMs) {
            $headers['grpc-timeout'] = $timeoutMs . 'm';
        }

        /** @var array<string, list<string>> $trailers */
        $trailers = [];

        try {
            $response = $this->client->request('POST', ($this->connection->tls ? 'https://' : 'http://') . $this->connection->target . $method, [
                'body' => GrpcWire::frame($request->serializeToString()),
                'headers' => $headers,
                'version' => '2.0',
                'curl' => [\CURLOPT_HTTP_VERSION => $this->connection->tls ? \CURL_HTTP_VERSION_2_0 : \CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE],
                'http_errors' => false,
                'connect_timeout' => 10,
                // The server enforces grpc-timeout; Guzzle's own deadline is the backstop for a
                // peer that stops answering, hence the slack.
                'timeout' => null === $timeoutMs ? 0 : ($timeoutMs + 1000) / 1000,
                'on_trailers' => static function (array $received) use (&$trailers): void {
                    $trailers = $received;
                },
            ]);
        } catch (ConnectException $e) {
            $errno = $e->getHandlerContext()['errno'] ?? null;

            throw GrpcWire::failure(\CURLE_OPERATION_TIMEDOUT === $errno ? GrpcWire::DEADLINE_EXCEEDED : GrpcWire::UNAVAILABLE, $e->getMessage());
        } catch (GuzzleException $e) {
            throw GrpcWire::failure(GrpcWire::UNAVAILABLE, $e->getMessage());
        }

        [$code, $message] = GrpcWire::status(self::firstValues($response, $trailers), $response->getStatusCode());
        if (0 !== $code) {
            throw GrpcWire::failure($code, $message);
        }

        $reply = new $responseClass();
        $reply->mergeFromString(GrpcWire::unframe((string) $response->getBody()));

        return $reply;
    }

    /**
     * A trailers-only response carries its status in the headers, so both are read, trailers last.
     *
     * @param array<string, list<string>> $trailers
     *
     * @return array<string, string>
     */
    private static function firstValues(ResponseInterface $response, array $trailers): array
    {
        $values = [];
        foreach ($response->getHeaders() as $name => $lines) {
            $values[strtolower($name)] = $lines[0] ?? '';
        }
        foreach ($trailers as $name => $lines) {
            $values[strtolower($name)] = $lines[0] ?? '';
        }

        return $values;
    }
}
