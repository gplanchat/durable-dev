<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Fixtures;

use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;

/**
 * Contexte déclaré non JSON-sérialisable → échec catastrophique côté durable.
 *
 * @internal
 */
final class NonSerializableDeclaredFailure extends \RuntimeException implements DeclaredActivityFailureInterface
{
    public function __construct()
    {
        parent::__construct('non-serializable declared');
    }

    public function toActivityFailureContext(): array
    {
        $h = fopen('php://memory', 'rb');
        if (false === $h) {
            return [];
        }

        return ['resource' => $h];
    }

    public static function restoreFromActivityFailureContext(array $context): static
    {
        return new self();
    }
}
