<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Composer\Autoload\ClassLoader;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;

/**
 * A Laravel application with one Filament panel carrying the plugin, served over HTTP.
 *
 * No skeleton: the application lives in a temporary directory whose `vendor/` is the one running
 * the tests, so package discovery registers Filament, Livewire, durable-laravel and this package
 * exactly as it would in an application. `config/durable.php` picks the backend.
 */
abstract class PanelTestCase extends TestCase
{
    /** The durable-laravel backend the application runs on. */
    protected const BACKEND = 'memory';

    /**
     * The application's `config/durable.php`.
     *
     * @return array<string, mixed>
     */
    protected static function durableConfig(): array
    {
        return ['backend' => static::BACKEND];
    }

    public function createApplication(): Application
    {
        $vendor = \dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2);
        $base = sys_get_temp_dir() . '/durable-filament-' . getmypid() . '-' . static::BACKEND;
        foreach (['config', 'bootstrap/cache', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'] as $dir) {
            is_dir("$base/$dir") || mkdir("$base/$dir", 0o777, true);
        }
        is_link("$base/vendor") || symlink($vendor, "$base/vendor");
        // Blade guesses component classes under the application's namespace, read from here.
        file_put_contents("$base/composer.json", '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
        file_put_contents("$base/config/durable.php", '<?php return ' . var_export(static::durableConfig(), true) . ';');

        foreach ([
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'SESSION_DRIVER' => 'array',
            // The illuminate backend refuses a lock store that only excludes inside one process.
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'database',
        ] as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        $app = Application::configure($base)
            ->withProviders([TestPanelProvider::class])
            ->withMiddleware()
            ->withExceptions()
            ->create();
        $app->make(Kernel::class)->bootstrap();
        // What an application's routing does once the panels have named their routes.
        $app['router']->getRoutes()->refreshNameLookups();

        return $app;
    }

    /**
     * A run as the runtime records it on the configured backend: projected, and journaled.
     */
    protected function startRun(string $executionId): void
    {
        $projection = $this->app->make(WorkflowRunCatalogInterface::class);
        self::assertInstanceOf(WorkflowRunProjectionInterface::class, $projection);
        $projection->recordStart(ExecutionId::fromString($executionId), 'App\\ShipWorkflow');
        $this->app->make(EventStoreInterface::class)->append(new ExecutionStarted(ExecutionId::fromString($executionId), []));
    }
}
