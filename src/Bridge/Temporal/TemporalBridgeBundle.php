<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\DependencyInjection\TemporalBridgeExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Registers the single {@code temporal://} Messenger factory (journal + application).
 */
final class TemporalBridgeBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new TemporalBridgeExtension();
    }

    public function getPath(): string
    {
        return __DIR__;
    }
}
