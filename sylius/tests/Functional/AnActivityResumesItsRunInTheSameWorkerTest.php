<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Fixture\StampWorkflow;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #851: the resume an activity handler sends is consumed by the worker that ran the activity, not
 * left for the next `durable:worker`. It fails on PostgreSQL only, where the Doctrine transport
 * waits for a notification before it reads a queue again; CI runs MySQL, which polls.
 */
final class AnActivityResumesItsRunInTheSameWorkerTest extends KernelTestCase
{
    /** @return iterable<string, array{array<string, int|string>}> */
    public static function workerOptions(): iterable
    {
        yield 'time limit only' => [['--time-limit' => 5, '--sleep' => '0.1']];
        // The new run's resume, the activity, the resume sent before its append, the one sent after.
        yield 'message limit' => [['--limit' => 4, '--time-limit' => 5, '--sleep' => '0.1']];
    }

    /** @param array<string, int|string> $options */
    #[DataProvider('workerOptions')]
    public function testOneWorkerRunsTheActivityAndTheResumeThatFollows(array $options): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        // Leftovers of other tests would take the worker's messages.
        $connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name IN ('durable_workflows', 'durable_activities')");
        $executionId = ExecutionId::fromString('stamp-' . bin2hex(random_bytes(4)));

        try {
            self::getContainer()->get(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun($executionId, StampWorkflow::TYPE, []);

            $worker = new CommandTester((new Application(self::$kernel))->find('durable:worker'));
            $worker->execute($options);

            $completed = array_values(array_filter(
                [...self::getContainer()->get(EventStoreInterface::class)->readStream($executionId)],
                static fn(object $event): bool => $event instanceof ExecutionCompleted,
            ));
            self::assertCount(1, $completed, 'the run must complete within one worker invocation');
            self::assertSame('stamped order', $completed[0]->result());
        } finally {
            foreach (['durable_events', 'durable_workflow_metadata', 'durable_workflow_runs'] as $table) {
                $connection->executeStatement("DELETE FROM {$table} WHERE execution_id = ?", [$executionId->toString()]);
            }
            $connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name IN ('durable_workflows', 'durable_activities')");
        }
    }
}
