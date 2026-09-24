<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A run in a continue-as-new chain names its predecessor and its successor (#322).
 */
final class DiagnoseExecutionCommandChainTest extends TestCase
{
    public function testItPrintsThePredecessorAndTheSuccessor(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new ExecutionStarted('exec-mid', ['continuedFromExecutionId' => 'exec-first']));
        $store->append(new WorkflowContinuedAsNew('exec-mid', 'Next', [], [], 'exec-last'));

        $tester = new CommandTester(new DiagnoseExecutionCommand(
            new InMemoryWorkflowMetadataStore(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
        ));

        $tester->execute(['executionId' => 'exec-mid']);
        self::assertStringContainsString('Continued from: exec-first', $tester->getDisplay());
        self::assertStringContainsString('Continued as: exec-last', $tester->getDisplay());

        $tester->execute(['executionId' => 'exec-mid', '--json' => true]);
        $json = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('exec-first', $json['continuedFromExecutionId']);
        self::assertSame('exec-last', $json['continuedAsExecutionId']);
    }
}
