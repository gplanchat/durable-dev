<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Fixture\NapWorkflow;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A run with a timer, dispatched and then drained by `durable:worker` over the Doctrine transport.
 *
 * The bench used to route nothing: every durable message ran inside the request that sent it, on
 * a DBAL journal, and the first timer met the resume lock the request already held (#361). The
 * dashboard test could not see it, since it writes the journal by hand. This one goes through the
 * queue the way a shop would.
 */
final class TimerDrainedByWorkerTest extends KernelTestCase
{
    public function testTheWorkerComesBackForTheTimerAndTheRunCompletes(): void
    {
        self::bootKernel();
        $executionId = 'nap-' . bin2hex(random_bytes(4));

        self::getContainer()->get(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun($executionId, NapWorkflow::TYPE, []);

        $worker = new CommandTester((new Application(self::$kernel))->find('durable:worker'));
        // Long enough for the one-second timer to come due and be consumed.
        $worker->execute(['--time-limit' => 5]);

        self::assertStringContainsString('Consuming durable_workflows', $worker->getDisplay());
        $completed = array_values(array_filter(
            [...self::getContainer()->get(EventStoreInterface::class)->readStream($executionId)],
            static fn(object $event): bool => $event instanceof ExecutionCompleted,
        ));
        self::assertCount(1, $completed, 'the run must complete once, after its timer');
        self::assertSame('rested', $completed[0]->result());
    }
}
