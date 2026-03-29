<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Model\Execution;

use Gplanchat\DurableModule\Api\ExecutionBackendInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

final class ExecutionBackendResolver
{
    public const XML_PATH_BACKEND = 'durable/execution/backend';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DbalExecutionBackend $dbalExecutionBackend,
        private readonly TemporalExecutionBackend $temporalExecutionBackend,
    ) {
    }

    public function get(): ExecutionBackendInterface
    {
        $code = (string) $this->scopeConfig->getValue(
            self::XML_PATH_BACKEND,
            ScopeInterface::SCOPE_STORE,
        );

        return match ($code) {
            ExecutionBackendInterface::CODE_TEMPORAL => $this->temporalExecutionBackend,
            default => $this->dbalExecutionBackend,
        };
    }
}
