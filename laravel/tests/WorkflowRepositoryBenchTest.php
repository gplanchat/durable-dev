<?php

declare(strict_types=1);

namespace Tests;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\TestCase;

#[AsWorkflow('bench-parcel')]
final class ParcelWorkflow
{
    private string $status = 'waiting';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env, string $tag): string
    {
        $env->await(fn(): bool => 'packed' === $this->status, label: 'signal pack');

        return 'parcel ' . $tag;
    }

    #[AsSignalMethod('pack')]
    public function pack(): void
    {
        $this->status = 'packed';
    }

    #[AsQueryMethod('status')]
    public function status(): string
    {
        return $this->status;
    }
}

/** @extends WorkflowRepository<ParcelWorkflow> */
#[AsWorkflowRepository(ParcelWorkflow::class)]
final class ParcelRepository extends WorkflowRepository {}

/**
 * Task 4.5 on the Laravel bench: one workflow driven through a declared repository, resolved from
 * the container. Start, query, signal and result, on the memory backend, in-process: its dispatcher
 * runs each resume inline, so no step needs a drain.
 */
final class WorkflowRepositoryBenchTest extends TestCase
{
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
        $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
        $app['config']->set('durable.workflows', [ParcelWorkflow::class]);
        $app->bootstrapWith([RegisterFacades::class, RegisterProviders::class, BootProviders::class]);

        return $app;
    }

    public function testARepositoryStartsQueriesSignalsAndReturnsTheResult(): void
    {
        $repository = $this->app->make(ParcelRepository::class);

        $id = ExecutionId::generate();
        $repository->create($id)->start(tag: 'A1');

        $stub = $repository->get($id);
        self::assertSame('waiting', $stub->status());

        $stub->pack();

        self::assertSame('packed', $stub->status());
        self::assertSame('parcel A1', $stub->result());
    }
}
