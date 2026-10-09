<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Gplanchat\GrpcClient\GrpcException;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * The exception the bridge throws for a failed call: Messenger's, so a worker loop sees a
 * transport failure where it expects one, with the gRPC status code as its code.
 */
final class GrpcFailure
{
    private function __construct() {}

    public static function of(int $code, string $message, ?\Throwable $previous = null): TransportException
    {
        return new TransportException(\sprintf('Temporal gRPC error [%d]: %s', $code, $message), $code, $previous);
    }

    public static function from(GrpcException $e): TransportException
    {
        return self::of($e->getCode(), $e->statusMessage, $e);
    }
}
