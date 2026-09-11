<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\TemporalHttp;

/**
 * The bytes of a unary gRPC exchange: length-prefixed frames on the way in and out, and the
 * status carried in the response trailers.
 *
 * Kept free of curl so the framing and the status mapping have a test that needs no server.
 */
final class GrpcWire
{
    public const UNKNOWN = 2;

    public const DEADLINE_EXCEEDED = 4;

    public const UNIMPLEMENTED = 12;

    public const UNAVAILABLE = 14;

    private function __construct() {}

    /** One uncompressed frame: flag byte, big-endian length, payload. */
    public static function frame(string $message): string
    {
        return "\x00" . pack('N', \strlen($message)) . $message;
    }

    /** The payloads of every frame in the body, concatenated (a unary response holds one). */
    public static function unframe(string $body): string
    {
        $out = '';
        $offset = 0;
        $length = \strlen($body);
        while ($offset + 5 <= $length) {
            if ("\x00" !== $body[$offset]) {
                // ponytail: the client never sends grpc-accept-encoding, so a compressed frame
                // would be a server bug; support it the day a server sends one anyway.
                throw self::failure(self::UNIMPLEMENTED, 'Compressed gRPC frames are not supported.');
            }
            /** @var array{1: int} $size */
            $size = unpack('N', substr($body, $offset + 1, 4));
            if ($offset + 5 + $size[1] > $length) {
                throw self::failure(self::UNKNOWN, 'Truncated gRPC frame.');
            }
            $out .= substr($body, $offset + 5, $size[1]);
            $offset += 5 + $size[1];
        }
        if ($offset !== $length) {
            throw self::failure(self::UNKNOWN, 'Truncated gRPC frame.');
        }

        return $out;
    }

    /**
     * The gRPC status of a response, from its headers and trailers (lower-cased names).
     *
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: string}
     */
    public static function status(array $headers, int $httpStatus): array
    {
        if (!isset($headers['grpc-status'])) {
            return [self::UNKNOWN, \sprintf('HTTP %d without a grpc-status trailer: not a Temporal gRPC frontend?', $httpStatus)];
        }

        return [(int) $headers['grpc-status'], rawurldecode($headers['grpc-message'] ?? '')];
    }

    /** The call deadline in milliseconds from gRPC-style options (`timeout` in microseconds), 0 for none. */
    public static function timeoutMs(array $options): int
    {
        $timeout = $options['timeout'] ?? 0;

        return \is_int($timeout) && $timeout > 0 ? (int) ceil($timeout / 1000) : 0;
    }

    /** gRPC metadata (name => value or list of values) as header lines. */
    public static function metadataHeaders(array $metadata): array
    {
        $headers = [];
        foreach ($metadata as $name => $values) {
            foreach (\is_array($values) ? $values : [$values] as $value) {
                if (\is_string($value) || \is_int($value)) {
                    $headers[] = $name . ': ' . $value;
                }
            }
        }

        return $headers;
    }

    /** The same exception the ext-grpc path throws: the gRPC status code is the exception code. */
    public static function failure(int $code, string $message): \RuntimeException
    {
        return new \RuntimeException(\sprintf('Temporal gRPC error [%d]: %s', $code, $message), $code);
    }
}
