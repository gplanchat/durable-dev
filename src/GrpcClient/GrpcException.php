<?php

declare(strict_types=1);

namespace Gplanchat\GrpcClient;

/** A gRPC call that did not end in OK. The gRPC status code is the exception code. */
final class GrpcException extends \RuntimeException
{
    public function __construct(
        int $code,
        /** The status message as the server sent it, without the code prefix of {@see getMessage()}. */
        public readonly string $statusMessage,
    ) {
        parent::__construct(\sprintf('gRPC error [%d]: %s', $code, $statusMessage), $code);
    }
}
