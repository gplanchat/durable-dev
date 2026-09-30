<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament;

/**
 * The Filament colour of an outcome. A cancellation is grey: an outcome, not a breakdown.
 *
 * @internal
 */
final class StatusColor
{
    private function __construct() {}

    public static function of(string $status): string
    {
        return match ($status) {
            'running' => 'info',
            'completed' => 'success',
            'failed' => 'danger',
            default => 'gray',
        };
    }
}
