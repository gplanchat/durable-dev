<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--story` tells a run's history and ends with what it waits on, read from the run catalog.
 */
final class DiagnoseExecutionCommandStoryTest extends TestCase
{
    public function testItEndsWithTheLabelTheWorkflowGaveItsWait(): void
    {
        $store = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($store);
        $id = ExecutionId::fromString('exec-1');
        $store->append(new ExecutionStarted($id, []));
        $catalog->recordStart($id, 'OrderWorkflow');
        $catalog->recordWait($id, 'signal payment.confirmed');

        $tester = $this->tester($store, $catalog);
        $status = $tester->execute(['executionId' => 'exec-1', '--story' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('execution OrderWorkflow', $tester->getDisplay());
        self::assertStringContainsString('Waiting on: signal payment.confirmed', $tester->getDisplay());
    }

    public function testAnEndedRunSaysHowItEnded(): void
    {
        $store = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($store);
        $id = ExecutionId::fromString('exec-1');
        $catalog->recordStart($id, 'OrderWorkflow');
        $catalog->recordOutcome($id, WorkflowRunStatus::Completed);

        $tester = $this->tester($store, $catalog);
        $tester->execute(['executionId' => 'exec-1', '--story' => true]);

        self::assertStringContainsString('Ended: completed', $tester->getDisplay());
    }

    public function testAnUnknownRunSaysWhatToTryInstead(): void
    {
        $store = new InMemoryEventStore();
        $tester = $this->tester($store, new InMemoryWorkflowRunCatalog($store));

        $status = $tester->execute(['executionId' => 'nope', '--story' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No run "nope" in the run catalog', $tester->getDisplay());
        self::assertStringContainsString('durable:execution:diagnose nope', $tester->getDisplay());
    }

    public function testWithoutACatalogItSaysWhyAndWhatToDo(): void
    {
        $tester = $this->tester(new InMemoryEventStore(), null);

        $status = $tester->execute(['executionId' => 'exec-1', '--story' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('run catalog', $tester->getDisplay());
    }

    private function tester(InMemoryEventStore $store, ?WorkflowRunCatalogInterface $catalog): CommandTester
    {
        return new CommandTester(new DiagnoseExecutionCommand(
            new InMemoryWorkflowMetadataStore(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            runCatalog: $catalog,
        ));
    }
}
