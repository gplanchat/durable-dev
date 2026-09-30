<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\WorkflowEnvironment;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

interface TwoStepActivities
{
    #[AsActivityMethod('two.first')]
    public function first(): string;

    #[AsActivityMethod('two.second')]
    public function second(string $after): string;
}

final class TwoStepHandler implements TwoStepActivities
{
    /** @var list<string> */
    public static array $ran = [];

    /** @return list<string> */
    public static function ran(): array
    {
        return self::$ran;
    }

    public function first(): string
    {
        self::$ran[] = 'first';

        return 'a';
    }

    public function second(string $after): string
    {
        self::$ran[] = 'second';

        return 'b';
    }
}

#[AsWorkflow('two-step')]
final class TwoStepWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsWorkflowMethod]
    public function run(): string
    {
        $activities = $this->environment->activityStub(TwoStepActivities::class);

        return $this->environment->await($activities->second($this->environment->await($activities->first())));
    }
}

#[AsWorkflow('short-nap')]
final class ShortNapWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->sleep(0.1);

        return 'awake';
    }
}

/**
 * #603, the user's decision: on Laravel's memory backend, `dispatchNewWorkflowRun()` drives the
 * run in the caller's process, as the Symfony bundle's in-memory mode does. It used to be the null
 * dispatcher, and the run never started.
 */
final class AMemoryRunCompletesInTheCallersProcessTest extends TestCase
{
    public function testARunWithTwoActivitiesCompletesInTheCall(): void
    {
        TwoStepHandler::$ran = [];
        $app = $this->memory([TwoStepWorkflow::class], [TwoStepHandler::class]);

        $app->make(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun(ExecutionId::fromString('run-1'), 'two-step', []);

        self::assertTrue($app->make(WorkflowMetadataStore::class)->get(ExecutionId::fromString('run-1'))['completed'] ?? false);
        self::assertSame(['first', 'second'], TwoStepHandler::ran());
    }

    public function testARunThatSleepsWakesInTheCall(): void
    {
        $app = $this->memory([ShortNapWorkflow::class]);

        $app->make(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun(ExecutionId::fromString('run-2'), 'short-nap', []);

        self::assertTrue($app->make(WorkflowMetadataStore::class)->get(ExecutionId::fromString('run-2'))['completed'] ?? false);
    }

    /**
     * @param list<class-string> $workflows
     * @param list<class-string> $activityHandlers
     */
    private function memory(array $workflows, array $activityHandlers = []): Container
    {
        $app = new Container();
        $app->instance('config', new \ArrayObject(['durable' => ['backend' => 'memory', 'workflows' => $workflows, 'activity_handlers' => $activityHandlers]], \ArrayObject::ARRAY_AS_PROPS));
        (new DurableServiceProvider($app))->register();

        return $app;
    }
}
