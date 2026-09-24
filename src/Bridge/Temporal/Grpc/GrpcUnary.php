<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Gplanchat\Bridge\Temporal\Http\GrpcWire;
use Grpc\UnaryCall;

/**
 * @internal
 */
final class GrpcUnary
{
    /** @param UnaryCall<\Google\Protobuf\Internal\Message> $call */
    public static function wait(UnaryCall $call): object
    {
        /** @var array{0: object|null, 1: \stdClass} $pair */
        $pair = $call->wait();
        [$response, $status] = $pair;
        if (\Grpc\STATUS_OK !== ($status->code ?? -1)) {
            // The gRPC code becomes the exception code: NOT_FOUND (5) is benign on the
            // RespondActivityTask*, and telling it apart by the message would be string parsing.
            throw GrpcWire::failure((int) ($status->code ?? GrpcWire::UNKNOWN), (string) ($status->details ?? ''));
        }
        if (null === $response) {
            throw GrpcWire::failure(GrpcWire::UNKNOWN, 'empty response.');
        }

        return $response;
    }
}
