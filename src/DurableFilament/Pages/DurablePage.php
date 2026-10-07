<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Pages;

use Filament\Pages\Page;

/**
 * The seam between Filament 3 and 4, and all of it.
 *
 * A page declares its view, icon and group as static properties on Filament 3, and as properties
 * of another type on Filament 4 (`$view` is no longer static; the icon and the group also accept
 * enums). Redeclaring any of them is a fatal error on one of the two lines. The pages therefore
 * override the getters, whose Filament 3 return types are a subset of Filament 4's. The rest of
 * the page API they use (`$slug`, `$shouldRegisterNavigation`, `getUrl()`, `getTitle()`,
 * `getNavigationLabel()`) and the Blade components their views use (`filament-panels::page`,
 * `filament::section`, `badge`, `link`, `button`, `input`, `input.select`) are the same on both.
 */
abstract class DurablePage extends Page
{
    abstract protected function durableView(): string;

    public function getView(): string
    {
        return $this->durableView();
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-arrow-path';
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }
}
