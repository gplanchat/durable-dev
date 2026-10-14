<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Fixture\ApprovalRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * One run on the DBAL journal driven through a declared repository: start, query, signal, query,
 * result. Each operation queues a message on the Doctrine transport, so `durable:worker` drains
 * it between the steps, as a deployment's worker would.
 */
final class AnApprovalIsDrivenThroughItsRepositoryTest extends KernelTestCase
{
    public function testStartQuerySignalQueryResult(): void
    {
        self::bootKernel();
        $stub = self::getContainer()->get(ApprovalRepository::class)->create()->start(document: 'invoice-42');

        $this->drain();
        self::assertSame('pending', $stub->status());

        $stub->approve();
        $this->drain();

        self::assertSame('approved', $stub->status());
        self::assertSame('invoice-42 approved', $stub->result());
    }

    private function drain(): void
    {
        (new CommandTester((new Application(self::$kernel))->find('durable:worker')))->execute(['--time-limit' => 3, '--sleep' => '0.1']);
    }
}
