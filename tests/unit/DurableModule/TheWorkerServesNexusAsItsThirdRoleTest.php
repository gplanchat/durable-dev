<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\DurableModule\Console\Command\RunWorkerCommand;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bin/magento durable:worker --role=nexus` serves the module's Nexus operations (#668), bounded
 * and supervised like the journal and activity roles.
 */
final class TheWorkerServesNexusAsItsThirdRoleTest extends TestCase
{
    public function testTheNexusRoleTakesItsTurnsFromTheNexusWorker(): void
    {
        $factory = new class extends RuntimeFactory {
            public function nexusWorker(): TemporalNexusWorker
            {
                throw new \LogicException('the Nexus worker was asked for');
            }
        };

        $this->expectExceptionMessage('the Nexus worker was asked for');

        (new CommandTester(new RunWorkerCommand($factory)))->execute(['--role' => 'nexus', '--max-tasks' => '1']);
    }

    public function testAnUnknownRoleNamesTheThreeItCouldHaveBeen(): void
    {
        $this->expectExceptionMessage('journal, activity or nexus');

        (new CommandTester(new RunWorkerCommand(new RuntimeFactory())))->execute(['--role' => 'nexxus']);
    }
}
