<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\TemporalHttp;

use Google\Protobuf\Internal\Message;
use Gplanchat\Bridge\Temporal\AbstractWorkflowServiceClient;
use Gplanchat\Bridge\Temporal\TemporalConnection;

/**
 * gRPC without ext-grpc: each unary call is one HTTP/2 POST through curl, with the gRPC frame
 * in the body and the status read from the trailers. Same port (7233), same protobuf messages,
 * so every RPC the bridge uses works here, task polling included.
 */
final class CurlGrpcWorkflowServiceClient extends AbstractWorkflowServiceClient
{
    private const SERVICE_PATH = '/temporal.api.workflowservice.v1.WorkflowService/';

    public function __construct(private readonly TemporalConnection $connection) {}

    protected function call(string $rpc, Message $request, string $responseClass, array $metadata, array $options): Message
    {
        $headers = ['content-type: application/grpc', 'te: trailers', 'user-agent: durable-bridge-temporal-http/php'];
        $timeoutMs = GrpcWire::timeoutMs($options);
        if ($timeoutMs > 0) {
            $headers[] = 'grpc-timeout: ' . $timeoutMs . 'm';
        }

        $collected = [];
        $curl = curl_init(($this->connection->tls ? 'https://' : 'http://') . $this->connection->target . self::SERVICE_PATH . $rpc);
        curl_setopt_array($curl, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => GrpcWire::frame($request->serializeToString()),
            \CURLOPT_HTTPHEADER => array_merge($headers, GrpcWire::metadataHeaders($metadata)),
            // h2c needs prior knowledge (no Upgrade dance); over TLS, ALPN negotiates h2.
            \CURLOPT_HTTP_VERSION => $this->connection->tls ? \CURL_HTTP_VERSION_2_0 : \CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CONNECTTIMEOUT => 10,
            // The server enforces grpc-timeout; curl's own deadline is the backstop for a peer that
            // stops answering, hence the slack.
            \CURLOPT_TIMEOUT_MS => $timeoutMs > 0 ? $timeoutMs + 1000 : 0,
            \CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$collected): int {
                $colon = strpos($line, ':');
                if (false !== $colon) {
                    $collected[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }

                return \strlen($line);
            },
        ]);

        $body = curl_exec($curl);
        if (!\is_string($body)) {
            $code = \CURLE_OPERATION_TIMEDOUT === curl_errno($curl) ? GrpcWire::DEADLINE_EXCEEDED : GrpcWire::UNAVAILABLE;

            throw GrpcWire::failure($code, curl_error($curl));
        }

        /** @var array<string, string> $collected */
        [$code, $message] = GrpcWire::status($collected, (int) curl_getinfo($curl, \CURLINFO_RESPONSE_CODE));
        if (0 !== $code) {
            throw GrpcWire::failure($code, $message);
        }

        $response = new $responseClass();
        $response->mergeFromString(GrpcWire::unframe($body));

        return $response;
    }
}
