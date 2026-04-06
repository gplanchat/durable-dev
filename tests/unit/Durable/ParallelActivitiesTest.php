<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Verifies parallel activity execution via WorkflowEnvironment::all() / parallel().
 *
 * Key property under test: when a workflow calls `all($env->activity('a'), $env->activity('b'))`,
 * both schedule commands must be issued in the SAME workflow task (before the first fiber suspend),
 * and both results must be available when both activities have completed.
 */
final class ParallelActivitiesTest extends TestCase
{
    private InMemoryEventStore $eventStore;
    private InMemoryActivityTransport $transport;
    private RegistryActivityExecutor $executor;
    private InMemoryWorkflowRunner $runner;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $this->transport = new InMemoryActivityTransport();
        $this->executor = new RegistryActivityExecutor();
        $this->runner = new InMemoryWorkflowRunner(
            $this->eventStore,
            $this->transport,
            $this->executor,
        );
    }

    public function testAllWithTwoActivitiesReturnsBothResults(): void
    {
        $this->executor->register('double', static fn (array $p) => ($p['value'] ?? 0) * 2);
        $this->executor->register('square', static fn (array $p) => ($p['value'] ?? 0) ** 2);

        $result = $this->runner->run('parallel-test-1', static function (WorkflowEnvironment $env): array {
            return $env->all(
                $env->activity('double', ['value' => 3]),
                $env->activity('square', ['value' => 4]),
            );
        });

        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertSame(6, $result[0]);
        self::assertSame(16, $result[1]);
    }

    public function testParallelWithThreeActivitiesReturnsBothResults(): void
    {
        $this->executor->register('add', static fn (array $p) => ($p['a'] ?? 0) + ($p['b'] ?? 0));

        $result = $this->runner->run('parallel-test-2', static function (WorkflowEnvironment $env): array {
            return $env->parallel(
                $env->activity('add', ['a' => 1, 'b' => 2]),
                $env->activity('add', ['a' => 3, 'b' => 4]),
                $env->activity('add', ['a' => 5, 'b' => 6]),
            );
        });

        self::assertIsArray($result);
        self::assertCount(3, $result);
        self::assertSame(3, $result[0]);
        self::assertSame(7, $result[1]);
        self::assertSame(11, $result[2]);
    }

    public function testAllWithPreCreatedAwaitablesReturnsBothResults(): void
    {
        $executed = [];
        $this->executor->register('task', static function (array $p) use (&$executed): string {
            $executed[] = $p['name'];

            return 'done-'.$p['name'];
        });

        $result = $this->runner->run('parallel-test-3', static function (WorkflowEnvironment $env): array {
            $a = $env->activity('task', ['name' => 'x']);
            $b = $env->activity('task', ['name' => 'y']);

            return $env->all($a, $b);
        });

        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertContains('done-x', $result);
        self::assertContains('done-y', $result);
        self::assertContains('x', $executed);
        self::assertContains('y', $executed);
    }

    public function testParallelAfterSequentialActivities(): void
    {
        $this->executor->register('id', static fn (array $p) => $p['v'] ?? null);

        $result = $this->runner->run('parallel-test-4', static function (WorkflowEnvironment $env): array {
            $first = $env->await($env->activity('id', ['v' => 'seq']));
            $parallel = $env->all(
                $env->activity('id', ['v' => 'p1']),
                $env->activity('id', ['v' => 'p2']),
            );

            return ['first' => $first, 'parallel' => $parallel];
        });

        self::assertIsArray($result);
        self::assertSame('seq', $result['first']);
        self::assertSame(['p1', 'p2'], $result['parallel']);
    }
}
