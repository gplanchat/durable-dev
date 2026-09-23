<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Registers nothing any more: the Durable bundle builds the Temporal workers from
 * `durable.temporal.dsn`. Kept so that a kernel still listing it keeps booting.
 *
 * @deprecated remove it from config/bundles.php
 */
final class TemporalBridgeBundle extends Bundle
{
    public function getPath(): string
    {
        return __DIR__;
    }
}
