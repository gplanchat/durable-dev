<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament;

use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;

/**
 * The views, the translations, and the projection the pages read.
 *
 * The catalog is whichever one durable-laravel binds for its backend: in-memory, Illuminate or
 * Temporal. Nothing here names a backend, and durable-laravel knows nothing of this package.
 */
final class DurableFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RunDashboard::class, fn($app): RunDashboard => new RunDashboard(
            $app->bound(WorkflowRunCatalogInterface::class) ? $app->make(WorkflowRunCatalogInterface::class) : null,
            $app->bound(ClockInterface::class) ? $app->make(ClockInterface::class) : null,
            // The application's own, as the profiler and diagnose use it (#507); the key pattern otherwise.
            $app->bound(PayloadRedactorInterface::class) ? $app->make(PayloadRedactorInterface::class) : null,
        ));
        $this->app->singleton(WorkerPresence::class, fn($app): WorkerPresence => WorkerPresence::of($app));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'durable-filament');
        $this->loadTranslationsFrom(__DIR__ . '/lang', 'durable-filament');
    }
}
