<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate;

use Illuminate\Support\ServiceProvider;

/**
 * Publishes the bridge's migrations, and nothing else.
 *
 * This is **not** the Laravel integration's service provider: it registers no store, it binds
 * no interface, it adds no command. A set of stores does not decide how an application wires
 * them — that is the integration package's job, and the README has said so since the very
 * first day.
 *
 * What it does is what no other package can do in its place: tell Laravel where **its** migrations
 * are. Without it, every application would copy them by hand, and a schema fix would never reach
 * the applications already installed.
 *
 * ```bash
 * php artisan vendor:publish --tag=durable-migrations
 * php artisan migrate
 * ```
 *
 * Publishing is not mandatory: the migrations are already loaded from the package, so
 * `php artisan migrate` is enough. You publish when you want to modify them — and from then on,
 * they belong to the application.
 */
final class DurableIlluminateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $migrations = __DIR__ . '/Migrations';

        $this->loadMigrationsFrom($migrations);

        if ($this->app->runningInConsole()) {
            $this->publishes(
                [$migrations => $this->app->databasePath('migrations')],
                'durable-migrations',
            );
        }
    }
}
