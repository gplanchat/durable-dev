<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Grpc\UnaryCall;

/**
 * @internal
 */
final class GrpcUnary
{
    public static function wait(UnaryCall $call): object
    {
        /** @var array{0: object|null, 1: \stdClass} $pair */
        $pair = $call->wait();
        [$response, $status] = $pair;
        if (\Grpc\STATUS_OK !== ($status->code ?? -1)) {
            // Le code gRPC devient le code de l'exception : NOT_FOUND (5) est bénin sur les
            // RespondActivityTask*, et le distinguer par le message serait de l'analyse de chaîne.
            throw new \RuntimeException(
                \sprintf('Temporal gRPC error [%s]: %s', (string) ($status->code ?? '?'), (string) ($status->details ?? '')),
                (int) ($status->code ?? -1),
            );
        }
        if (null === $response) {
            throw new \RuntimeException('Temporal gRPC returned empty response.');
        }

        return $response;
    }
}
