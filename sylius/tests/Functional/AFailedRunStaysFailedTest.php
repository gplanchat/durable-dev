<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Fixture\FailingWorkflow;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #851: `sylius.command_bus` wraps every handler in the `doctrine_transaction` middleware, and the
 * worker hands the resume to that bus. On the shop's own connection, the journal's
 * `WorkflowExecutionFailed` sat in that transaction, and the rollback that follows the handler's
 * exception erased it: the run stayed Running. The journal has a connection of its own (DUR054).
 */
final class AFailedRunStaysFailedTest extends KernelTestCase
{
    /** @return iterable<string, array{string, array<string, mixed>, class-string<\Throwable>}> */
    public static function failingRuns(): iterable
    {
        yield 'a workflow that throws' => [FailingWorkflow::TYPE, [], \RuntimeException::class];
    }

    /**
     * @param array<string, mixed>     $payload
     * @param class-string<\Throwable> $failureClass
     */
    #[DataProvider('failingRuns')]
    public function testTheShopsRollbackLeavesTheFailureInTheJournal(string $workflowType, array $payload, string $failureClass): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        // Leftovers of other tests, retries included, would take the worker's one message.
        $connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name IN ('durable_workflows', 'durable_activities')");
        $executionId = ExecutionId::fromString('failing-' . bin2hex(random_bytes(4)));

        try {
            self::getContainer()->get(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun($executionId, $workflowType, $payload);

            $worker = new CommandTester((new Application(self::$kernel))->find('durable:worker'));
            $worker->execute(['--limit' => 1, '--time-limit' => 5]);

            $failed = array_values(array_filter(
                [...self::getContainer()->get(EventStoreInterface::class)->readStream($executionId)],
                static fn(object $event): bool => $event instanceof WorkflowExecutionFailed,
            ));
            self::assertCount(1, $failed, 'the run must end failed, and the journal must say so');
            self::assertSame($failureClass, $failed[0]->failureClass());
        } finally {
            // A failed run left behind would move the dashboard test's first page.
            foreach (['durable_events', 'durable_workflow_metadata', 'durable_workflow_runs'] as $table) {
                $connection->executeStatement("DELETE FROM {$table} WHERE execution_id = ?", [$executionId->toString()]);
            }
            $connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name IN ('durable_workflows', 'durable_activities')");
        }
    }
}
