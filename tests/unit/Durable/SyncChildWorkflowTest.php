<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * A child executed **inline** (ChildWorkflowRunner without a deferred Messenger start) journalled
 * its outcome with completeWorkflow()/failWorkflow() on the PARENT's buffer: the parent's journal
 * was closed with the child's result, and the ChildWorkflowCompleted that replay looks for never
 * existed.
 */
final class SyncChildWorkflowTest extends TestCase
{
    private InMemoryEventStore $eventStore;
    private WorkflowRegistry $registry;
    private ExecutionEngine $engine;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $this->registry = new WorkflowRegistry();
        $runtime = new ExecutionRuntime($this->eventStore, $transport, $executor, 0, null, true);

        $this->engine = new ExecutionEngine(
            $this->eventStore,
            $runtime,
            new ChildWorkflowRunner($this->eventStore, $runtime, $this->registry, $executor, 0, false),
        );
    }

    public function testChildOutcomeLandsOnTheParentAsAChildEvent(): void
    {
        $this->registry->registerClass(EchoingChild::class);

        $result = $this->engine->start('parent-1', static fn(WorkflowEnvironment $env): string
            => 'parent-saw:' . $env->await($env->childWorkflowStub(EchoingChild::class)->run()));

        self::assertSame('parent-saw:child-result', $result);

        $types = $this->shortNames('parent-1');
        self::assertSame(
            ['ExecutionStarted', 'ChildWorkflowScheduled', 'ChildWorkflowCompleted', 'ExecutionCompleted'],
            $types,
        );

        // A single ExecutionCompleted, and it carries the PARENT's result.
        $completed = array_values(array_filter(
            iterator_to_array($this->eventStore->readStream('parent-1'), false),
            static fn(object $e): bool => $e instanceof ExecutionCompleted,
        ));
        self::assertCount(1, $completed);
        self::assertSame('parent-saw:child-result', $completed[0]->result());
    }

    public function testChildIsNotReExecutedWhenTheParentReplays(): void
    {
        $childRuns = 0;
        $this->registry->registerFactory('Child', static function (array $i) use (&$childRuns) {
            return static function (WorkflowEnvironment $env) use (&$childRuns): string {
                ++$childRuns;

                return 'child-result';
            };
        });

        $handler = static fn(WorkflowEnvironment $env): string
            => 'parent-saw:' . $env->await($env->childWorkflowStub(EchoingChild::class)->run());

        $this->engine->start('parent-2', $handler);
        self::assertSame(1, $childRuns);

        // The replay must read ChildWorkflowCompleted back, not restart the child.
        $this->engine->resume('parent-2', $handler);
        self::assertSame(1, $childRuns, 'the child must not be re-executed when the parent replays');
    }

    public function testFailingChildLandsAsChildWorkflowFailedNotAsAParentFailure(): void
    {
        $this->registry->registerClass(ExplodingChild::class);

        try {
            $this->engine->start('parent-3', static fn(WorkflowEnvironment $env): mixed
                => $env->await($env->childWorkflowStub(ExplodingChild::class)->run()));
        } catch (\Throwable) {
            // The parent does not handle the child's failure: that is expected here.
        }

        $types = $this->shortNames('parent-3');
        self::assertContains('ChildWorkflowFailed', $types);
        self::assertNotContains('ExecutionCompleted', $types);

        $failed = array_values(array_filter(
            iterator_to_array($this->eventStore->readStream('parent-3'), false),
            static fn(object $e): bool => $e instanceof ChildWorkflowFailed,
        ));
        self::assertStringContainsString('child exploded', $failed[0]->failureMessage());
    }

    public function testParentStaysActiveWhileTheChildCompletes(): void
    {
        $this->registry->registerClass(EchoingChild::class);

        $seenActive = null;
        $this->engine->start('parent-4', function (WorkflowEnvironment $env) use (&$seenActive): string {
            $child = $env->await($env->childWorkflowStub(EchoingChild::class)->run());
            $seenActive = ParentChildWorkflowCoordinator::isChildRunActive($this->eventStore, 'parent-4');

            return $child;
        });

        self::assertTrue($seenActive, 'the parent must not be seen as finished during its own run');
        self::assertNotEmpty(array_filter(
            iterator_to_array($this->eventStore->readStream('parent-4'), false),
            static fn(object $e): bool => $e instanceof ChildWorkflowCompleted,
        ));
    }

    /** @return list<string> */
    private function shortNames(string $executionId): array
    {
        $out = [];
        foreach ($this->eventStore->readStream($executionId) as $event) {
            $out[] = (new \ReflectionClass($event))->getShortName();
        }

        return $out;
    }
}

#[\Gplanchat\Durable\Attribute\AsWorkflow(name: 'Child')]
final class EchoingChild
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[\Gplanchat\Durable\Attribute\AsWorkflowMethod]
    public function run(): string
    {
        return 'child-result';
    }
}

#[\Gplanchat\Durable\Attribute\AsWorkflow(name: 'ExplodingChild')]
final class ExplodingChild
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[\Gplanchat\Durable\Attribute\AsWorkflowMethod]
    public function run(): never
    {
        throw new \DomainException('child exploded');
    }
}
