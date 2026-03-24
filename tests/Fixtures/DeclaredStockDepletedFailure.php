<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Fixtures;

use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;

/**
 * @internal
 */
final class DeclaredStockDepletedFailure extends \DomainException implements DeclaredActivityFailureInterface
{
    public function __construct(
        private readonly string $sku,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('Stock depleted for SKU %s', $sku), $code, $previous);
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function toActivityFailureContext(): array
    {
        return ['sku' => $this->sku];
    }

    public static function restoreFromActivityFailureContext(array $context): static
    {
        return new self((string) ($context['sku'] ?? ''));
    }
}
