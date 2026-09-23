<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\TemporalHttp;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\AbstractWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\TemporalConnection;

/**
 * The server's JSON gateway (grpc-gateway on the frontend HTTP port, 7243 by default): plain
 * HTTP/1.1 and JSON, no gRPC framing at all. It serves the client side only; see
 * {@see JsonGatewayRoutes} for what is missing and why.
 */
final class JsonGatewayWorkflowServiceClient extends AbstractWorkflowServiceClient
{
    public function __construct(private readonly TemporalConnection $connection) {}

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

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            \CURLOPT_CUSTOMREQUEST => $verb,
            \CURLOPT_POSTFIELDS => 'GET' === $verb ? null : $json,
            \CURLOPT_HTTPHEADER => array_merge(
                ['content-type: application/json', 'accept: application/json', 'user-agent: durable-bridge-temporal-http/php'],
                GrpcWire::metadataHeaders($metadata),
            ),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CONNECTTIMEOUT => 10,
            \CURLOPT_TIMEOUT_MS => GrpcWire::timeoutMs($options),
        ]);

        $body = curl_exec($curl);
        if (!\is_string($body)) {
            $code = \CURLE_OPERATION_TIMEDOUT === curl_errno($curl) ? GrpcWire::DEADLINE_EXCEEDED : GrpcWire::UNAVAILABLE;

            throw GrpcWire::failure($code, curl_error($curl));
        }

        $httpStatus = (int) curl_getinfo($curl, \CURLINFO_RESPONSE_CODE);
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
}
