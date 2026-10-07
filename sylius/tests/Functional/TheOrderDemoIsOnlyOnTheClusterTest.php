<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Durable\Workflow\OrderWorkflow;
use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #851: `OrderWorkflow` calls a Nexus operation, which only the cluster's journal can schedule
 * (DUR036). The workflow and `durable:demo:bill` are registered under `demo_caller` only, so a
 * profile on the SQL journal offers neither.
 */
final class TheOrderDemoIsOnlyOnTheClusterTest extends KernelTestCase
{
    public function testTheSqlJournalProfileHasNoOrderWorkflow(): void
    {
        self::bootKernel();

        self::assertFalse(self::getContainer()->get(WorkflowRegistry::class)->has(OrderWorkflow::TYPE));
    }

    public function testTheSqlJournalProfileHasNoBillingCommand(): void
    {
        self::bootKernel();

        self::assertFalse((new Application(self::$kernel))->has('durable:demo:bill'));
    }
}
