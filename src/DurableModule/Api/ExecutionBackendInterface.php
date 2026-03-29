<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Api;

/**
 * Backend d’exécution durable configuré pour le module Magento (DBAL ou Temporal).
 */
interface ExecutionBackendInterface
{
    public const CODE_DBAL = 'dbal';

    public const CODE_TEMPORAL = 'temporal';

    /**
     * @return self::CODE_*|string
     */
    public function getCode(): string;

    public function isOperational(): bool;
}
