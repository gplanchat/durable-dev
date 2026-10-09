<?php

declare(strict_types=1);

namespace Gplanchat\GrpcClient;

use Google\Protobuf\Internal\Message;

/**
 * gRPC without ext-grpc: each unary call is one HTTP/2 POST through curl, with the gRPC frame
 * in the body and the status read from the trailers.
 */
final readonly class CurlGrpcTransport implements GrpcTransport
{
    public function __construct(private readonly GrpcEndpoint $endpoint) {}

    /**
     * The curl options for the endpoint's CA and client certificate.
     *
     * @return array<int, string>
     */
    public static function tlsOptions(GrpcEndpoint $endpoint): array
    {
        return array_filter([
            \CURLOPT_CAINFO => $endpoint->tlsCa,
            \CURLOPT_SSLCERT => $endpoint->tlsCert,
            \CURLOPT_SSLKEY => $endpoint->tlsKey,
        ], static fn(?string $file): bool => null !== $file);
    }

    public function unary(string $method, Message $request, string $responseClass, array $metadata, ?int $timeoutMs): Message
    {
        $headers = ['content-type: application/grpc', 'te: trailers', 'user-agent: ' . $this->endpoint->userAgent];
        $timeoutMs ??= 0;
        if ($timeoutMs > 0) {
            $headers[] = 'grpc-timeout: ' . $timeoutMs . 'm';
        }

        $collected = [];
        $curl = curl_init(($this->endpoint->tls ? 'https://' : 'http://') . $this->endpoint->target . $method);
        curl_setopt_array($curl, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => GrpcWire::frame($request->serializeToString()),
            \CURLOPT_HTTPHEADER => array_merge($headers, GrpcWire::metadataHeaders($metadata + $this->endpoint->metadata())),
            // h2c needs prior knowledge (no Upgrade dance); over TLS, ALPN negotiates h2.
            \CURLOPT_HTTP_VERSION => $this->endpoint->tls ? \CURL_HTTP_VERSION_2_0 : \CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
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
        ] + self::tlsOptions($this->endpoint));

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
