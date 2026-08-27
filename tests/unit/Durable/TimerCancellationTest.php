<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * Le minuteur perdant d'un `any()` restait planifié : son échéance réveillait ensuite
 * l'exécution pour rien (Messenger) ou faisait naître un TimerCompleted fantôme.
 */
final class TimerCancellationTest extends TestCase
{
    private InMemoryEventStore $eventStore;
    private InMemoryWorkflowRunner $runner;

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStore();
        $executor = new RegistryActivityExecutor();
        $executor->register('fast', static fn(): string => 'winner');
        $this->runner = new InMemoryWorkflowRunner(
            $this->eventStore,
            new InMemoryActivityTransport(),
            $executor,
        );
    }

    public function testLoserTimerIsCancelledAndNeverFires(): void
    {
        $result = $this->runner->run('race-1', static fn(WorkflowEnvironment $env): mixed => $env->await($env->any(
            $env->activityStub(SuiteActivities::class)->fast(),
            $env->timer(3600.0),
        )));

        self::assertSame('winner', $result);

        $cancelled = $this->eventsOf(TimerCancelled::class);
        self::assertCount(1, $cancelled, 'le minuteur perdant doit être annulé une seule fois');
        self::assertSame([], $this->eventsOf(TimerCompleted::class));

        // Le calcul de réveil Messenger ne doit plus voir d'échéance en attente.
        self::assertNull(TimerWakeDelayCalculator::millisecondsUntilNextTimerDue(
            $this->eventStore,
            'race-1',
            microtime(true),
        ));
    }

    public function testCancellationIsNotDuplicatedOnReplay(): void
    {
        $handler = static fn(WorkflowEnvironment $env): mixed => $env->await($env->any(
            $env->activityStub(SuiteActivities::class)->fast(),
            $env->timer(3600.0),
        ));

        $this->runner->run('race-2', $handler);
        $this->runner->run('race-2', $handler);

        self::assertCount(1, $this->eventsOf(TimerCancelled::class, 'race-2'));
    }

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private function eventsOf(string $class, string $executionId = 'race-1'): array
    {
        $out = [];
        foreach ($this->eventStore->readStream($executionId) as $e) {
            if ($e instanceof $class) {
                $out[] = $e;
            }
        }

        return $out;
    }
}
