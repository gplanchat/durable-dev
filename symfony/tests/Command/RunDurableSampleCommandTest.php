<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke test : la commande durable:sample exécute un workflow enregistré (Messenger in-memory en test).
 *
 * @internal
 */
final class RunDurableSampleCommandTest extends KernelTestCase
{
    public function testGreetingWorkflowPrintsHello(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('durable:sample'));

        self::assertSame(0, $tester->execute([
            'workflow' => 'GreetingWorkflow',
            '--name' => 'PHPUnit',
        ]), $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('Hello', $display);
        self::assertStringContainsString('PHPUnit', $display);
    }

    public function testParentCallsEchoChildWorkflowReturnsUppercasedText(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('durable:sample'));

        self::assertSame(0, $tester->execute([
            'workflow' => 'ParentCallsEchoChildWorkflow',
            '--text' => 'child-ci',
        ]), $tester->getDisplay());

        self::assertStringContainsString('CHILD-CI', $tester->getDisplay());
    }

    public function testTimerThenTickWorkflowCompletesWithTick(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('durable:sample'));

        self::assertSame(0, $tester->execute([
            'workflow' => 'TimerThenTickWorkflow',
            '--seconds' => '0.01',
        ]), $tester->getDisplay());

        self::assertStringContainsString('tick', $tester->getDisplay());
    }

    public function testParallelChildEchoWorkflowReturnsTwoUppercasedStrings(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('durable:sample'));

        self::assertSame(0, $tester->execute([
            'workflow' => 'ParallelChildEchoWorkflow',
            '--first' => 'Aa',
            '--second' => 'Bb',
        ]), $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('AA', $display);
        self::assertStringContainsString('BB', $display);
    }

    public function testParallelGreetingWorkflowReturnsBothGreetings(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('durable:sample'));

        self::assertSame(0, $tester->execute([
            'workflow' => 'ParallelGreetingWorkflow',
            '--first' => 'P1',
            '--second' => 'P2',
        ]), $tester->getDisplay());

        $display = $tester->getDisplay();
        self::assertStringContainsString('Hello, P1!', $display);
        self::assertStringContainsString('Hello, P2!', $display);
    }

    public function testSideEffectRandomIdWorkflowReturnsEightHexChars(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('durable:sample'));

        self::assertSame(0, $tester->execute([
            'workflow' => 'SideEffectRandomIdWorkflow',
        ]), $tester->getDisplay());

        self::assertMatchesRegularExpression('/[a-f0-9]{8}/', $tester->getDisplay());
    }
}
