<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle\Command;

use Gplanchat\Durable\Bundle\Command\DebugDurableCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use unit\Durable\Fixtures\ShippingWorkflow;

/**
 * `debug:durable`, in the style of `debug:messenger`: the registered workflows, then one workflow's handlers.
 */
final class DebugDurableCommandTest extends TestCase
{
    public function testWithoutArgumentItListsTheRegisteredWorkflows(): void
    {
        $tester = new CommandTester(new DebugDurableCommand([ShippingWorkflow::class]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('shipping', $tester->getDisplay());
        self::assertStringContainsString(ShippingWorkflow::class, $tester->getDisplay());
    }

    public function testWithATypeItShowsTheEntryPointAndEachHandler(): void
    {
        $tester = new CommandTester(new DebugDurableCommand([ShippingWorkflow::class]));

        self::assertSame(Command::SUCCESS, $tester->execute(['workflow' => 'shipping']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('ship(string $orderId, int $parcels = …): array', $display);
        self::assertStringContainsString('parcel.picked', $display);
        self::assertStringContainsString('status(): string', $display);
        self::assertStringContainsString('change-address', $display);
    }

    public function testTheClassNameWorksAsWellAsTheType(): void
    {
        $tester = new CommandTester(new DebugDurableCommand([ShippingWorkflow::class]));

        self::assertSame(Command::SUCCESS, $tester->execute(['workflow' => ShippingWorkflow::class]));
        self::assertStringContainsString('parcel.picked', $tester->getDisplay());
    }

    public function testAnUnknownWorkflowNamesTheRegisteredOnes(): void
    {
        $tester = new CommandTester(new DebugDurableCommand([ShippingWorkflow::class]));

        self::assertSame(Command::FAILURE, $tester->execute(['workflow' => 'nope']));
        self::assertStringContainsString('No workflow "nope" is registered', $tester->getDisplay());
        self::assertStringContainsString('shipping', $tester->getDisplay());
    }

    public function testWithoutAnyWorkflowItSaysHowToRegisterOne(): void
    {
        $tester = new CommandTester(new DebugDurableCommand([]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('#[AsWorkflow]', $tester->getDisplay());
    }
}
