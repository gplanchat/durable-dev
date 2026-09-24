<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Http;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\AbstractWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * The server's JSON gateway (grpc-gateway on the frontend HTTP port, 7243 by default): plain
 * HTTP/1.1 and JSON, no gRPC framing at all. It serves the client side only; see
 * {@see JsonGatewayRoutes} for what is missing and why.
 *
 * The exchange goes through curl, or through any PSR-18 client handed as {@see Psr18Http}. PSR-18
 * has no per-request timeout: over it, the deadline is the client's own configuration.
 */
final class JsonGatewayWorkflowServiceClient extends AbstractWorkflowServiceClient
{
    public function __construct(
        private readonly TemporalConnection $connection,
        private readonly ?Psr18Http $http = null,
    ) {}

    protected function call(string $rpc, Message $request, string $responseClass, array $metadata, array $options): Message
    {
        $route = JsonGatewayRoutes::ROUTES[$rpc] ?? null;
        if (null === $route) {
            throw GrpcWire::failure(GrpcWire::UNIMPLEMENTED, \sprintf('%s has no HTTP binding on the Temporal server (task polling never has); use transport=grpc-curl.', $rpc));
        }
        [$verb, $template] = $route;

        $json = $request->serializeToJsonString();
        /** @var array<string, mixed> $fields */
        $fields = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $url = ($this->connection->tls ? 'https://' : 'http://') . $this->connection->target . JsonGatewayRequest::path($template, $fields);
        if ('GET' === $verb) {
            $query = JsonGatewayRequest::query($fields);
            $url .= '' === $query ? '' : '?' . $query;
        }

        $headers = ['content-type' => ['application/json'], 'accept' => ['application/json'], 'user-agent' => ['durable-bridge-temporal/php']] + $metadata;
        [$httpStatus, $body] = null === $this->http
            ? self::overCurl($url, $verb, $json, $headers, $options)
            : self::overPsr18($this->http, $url, $verb, $json, $headers);

        if (200 === $httpStatus) {
            $response = new $responseClass();
            $response->mergeFromJsonString($body, true);

            return $response;
        }

        // The gateway answers a gRPC failure with {"code": <grpc status>, "message": ...}.
        $error = json_decode($body, true);
        $code = \is_array($error) && \is_int($error['code'] ?? null) ? $error['code'] : GrpcWire::UNKNOWN;
        $message = \is_array($error) && \is_string($error['message'] ?? null)
            ? $error['message']
            : \sprintf('HTTP %d from the gateway: %s', $httpStatus, substr($body, 0, 200));

        throw GrpcWire::failure($code, $message);
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $options
     *
     * @return array{int, string} [HTTP status, body]
     */
    private static function overCurl(string $url, string $verb, string $json, array $headers, array $options): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            \CURLOPT_CUSTOMREQUEST => $verb,
            \CURLOPT_POSTFIELDS => 'GET' === $verb ? null : $json,
            \CURLOPT_HTTPHEADER => GrpcWire::metadataHeaders($headers),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CONNECTTIMEOUT => 10,
            \CURLOPT_TIMEOUT_MS => GrpcWire::timeoutMs($options),
        ]);

        $body = curl_exec($curl);
        if (!\is_string($body)) {
            $code = \CURLE_OPERATION_TIMEDOUT === curl_errno($curl) ? GrpcWire::DEADLINE_EXCEEDED : GrpcWire::UNAVAILABLE;

            throw GrpcWire::failure($code, curl_error($curl));
        }

        return [(int) curl_getinfo($curl, \CURLINFO_RESPONSE_CODE), $body];
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array{int, string} [HTTP status, body]
     */
    private static function overPsr18(Psr18Http $http, string $url, string $verb, string $json, array $headers): array
    {
        $request = $http->requests->createRequest($verb, $url);
        foreach ($headers as $name => $values) {
            $request = $request->withHeader($name, \is_array($values) ? array_map('strval', $values) : (string) $values);
        }
        if ('GET' !== $verb) {
            $request = $request->withBody($http->streams->createStream($json));
        }

        try {
            $response = $http->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw GrpcWire::failure(GrpcWire::UNAVAILABLE, $e->getMessage());
        }

        return [$response->getStatusCode(), (string) $response->getBody()];
    }
}
