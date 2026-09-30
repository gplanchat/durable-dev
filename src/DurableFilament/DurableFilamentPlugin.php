<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Gplanchat\Durable\Filament\Pages\Run;
use Gplanchat\Durable\Filament\Pages\Runs;

/**
 * The Durable dashboard in a Filament panel: `->plugin(DurableFilamentPlugin::make())`.
 *
 * It observes and never runs a workflow. `Plugin` has the same three methods on Filament 3 and 4.
 */
final readonly class DurableFilamentPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'durable';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([Runs::class, Run::class]);
    }

    public function boot(Panel $panel): void {}
}
