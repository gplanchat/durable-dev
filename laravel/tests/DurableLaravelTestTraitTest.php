<?php

declare(strict_types=1);

namespace Tests;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Laravel\Testing\DurableLaravelTestTrait;
use Gplanchat\Durable\WorkflowEnvironment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\TestCase;

#[AsWorkflow('test-helper-greet')]
final class GreetWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsWorkflowMethod]
    public function run(string $name): string
    {
        $this->environment->sleep(0.05);

        return 'Hello, ' . $name . '!';
    }
}

#[AsWorkflow('test-helper-boom')]
final class BoomWorkflow
{
    #[AsWorkflowMethod]
    public function run(): never
    {
        throw new \InvalidArgumentException('no');
    }
}

/**
 * #983: the Laravel counterpart of `DurableBundleTestTrait`, booted in-process on the bench's
 * application with the memory backend.
 */
final class DurableLaravelTestTraitTest extends TestCase
{
    use DurableLaravelTestTrait;

    /** @var array<string, array{getenv: string|false, env: mixed, server: mixed}> */
    private array $savedEnvironment = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        // The process's environment outlives this test: NexusBenchTest hands getenv() to its children.
        foreach ($this->savedEnvironment as $name => $previous) {
            false === $previous['getenv'] ? putenv($name) : putenv($name . '=' . $previous['getenv']);
            foreach (['env' => '_ENV', 'server' => '_SERVER'] as $key => $global) {
                if (null === $previous[$key]) {
                    unset($GLOBALS[$global][$name]);
                } else {
                    $GLOBALS[$global][$name] = $previous[$key];
                }
            }
        }
        $this->savedEnvironment = [];
    }

    public function createApplication()
    {
        foreach (['DURABLE_BACKEND' => 'memory', 'CACHE_STORE' => 'array'] as $name => $value) {
            $this->savedEnvironment[$name] ??= ['getenv' => getenv($name), 'env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        $app = require \dirname(__DIR__) . '/bootstrap/app.php';
        $app->make(Kernel::class);
        // The kernel's bootstrap in two halves, to declare the test workflows once the config is loaded.
        $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
        $app['config']->set('durable.workflows', [GreetWorkflow::class, BoomWorkflow::class]);
        $app->bootstrapWith([RegisterFacades::class, RegisterProviders::class, BootProviders::class]);

        return $app;
    }

    public function testAWorkflowStartsDrainsAndReturnsItsResult(): void
    {
        $executionId = $this->dispatchWorkflow(GreetWorkflow::class, ['name' => 'World']);

        $this->drainUntilSettled($executionId);

        $this->assertWorkflowResultEquals($executionId, 'Hello, World!');
    }

    public function testAFailingWorkflowIsAssertedOnTheJournal(): void
    {
        $executionId = $this->dispatchWorkflow(BoomWorkflow::class);

        $this->drainUntilSettled($executionId);

        $this->assertWorkflowFailed($executionId, \InvalidArgumentException::class);
    }

    public function testAnErrorUnrelatedToTheDrainedRunIsRethrown(): void
    {
        $completed = $this->dispatchWorkflow(GreetWorkflow::class, ['name' => 'World']);
        $this->drainUntilSettled($completed);

        // A later run fails: its error is not the journal's failure for $completed, so it must surface.
        $this->dispatchWorkflow(BoomWorkflow::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->drainUntilSettled($completed);
    }
}
