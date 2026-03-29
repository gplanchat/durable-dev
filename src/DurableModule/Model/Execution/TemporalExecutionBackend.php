<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Model\Execution;

use Gplanchat\DurableModule\Api\ExecutionBackendInterface;

/**
 * Placeholder mode Temporal : pas de RoadRunner ; implémentation worker/client à brancher (ADR).
 */
final class TemporalExecutionBackend implements ExecutionBackendInterface
{
    public function getCode(): string
    {
        return self::CODE_TEMPORAL;
    }

    public function isOperational(): bool
    {
        return false;
    }
}
