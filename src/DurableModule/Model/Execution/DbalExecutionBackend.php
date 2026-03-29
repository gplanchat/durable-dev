<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Model\Execution;

use Gplanchat\DurableModule\Api\ExecutionBackendInterface;

final class DbalExecutionBackend implements ExecutionBackendInterface
{
    public function getCode(): string
    {
        return self::CODE_DBAL;
    }

    public function isOperational(): bool
    {
        return true;
    }
}
