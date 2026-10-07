<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Laravel\Queue\InProcessWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\WorkflowEnvironment;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

interface InjectedStepActivities
{
    #[AsActivityMethod('injected.first')]
    public function first(): string;

    #[AsActivityMethod('injected.second')]
    public function second(string $after): string;
}

final class InjectedStepHandler implements InjectedStepActivities
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
        self::$ran[] = 'second after ' . $after;

        return 'b';
    }
}

#[AsWorkflow('injected-steps')]
final class InjectedStepsWorkflow
{
    /** @param ActivityStub<InjectedStepActivities> $steps */
    #[AsWorkflowMethod]
    public function run(
        #[Activities(InjectedStepActivities::class)]
        ActivityStub $steps,
        WorkflowEnvironment $env,
    ): string {
        return $env->await($steps->second($env->await($steps->first())));
    }
}

/**
 * The documentation injects activity stubs as `#[Activities]` arguments of the workflow method,
 * on every host. Laravel lists its workflows in `config/durable.php` rather than autoconfiguring
 * them: this is the service provider's registry, running a workflow written that way.
 */
final class AnActivitiesArgumentIsInjectedTest extends TestCase
{
    public function testTheInjectedStubRunsTheActivities(): void
    {
        InjectedStepHandler::$ran = [];
        $app = new Container();
        $app->instance('config', new \ArrayObject(['durable' => [
            'backend' => 'memory',
            'workflows' => [InjectedStepsWorkflow::class],
            'activity_handlers' => [InjectedStepHandler::class],
        ]], \ArrayObject::ARRAY_AS_PROPS));
        (new DurableServiceProvider($app))->register();

        $app->make(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun(ExecutionId::fromString('injected-1'), 'injected-steps', []);
        // On memory, a start is queued and runs when the queue is drained (#881).
        $app->make(InProcessWorkflowResumeDispatcher::class)->drain();

        self::assertTrue($app->make(WorkflowMetadataStore::class)->get(ExecutionId::fromString('injected-1'))['completed'] ?? false);
        self::assertSame(['first', 'second after a'], InjectedStepHandler::ran());
    }
}
