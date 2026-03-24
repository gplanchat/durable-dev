<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Vérifie que la commande exemple crée les tables DBAL Durable puis devient idempotente.
 *
 * @internal
 */
final class DurableSchemaInitCommandTest extends KernelTestCase
{
    public function testSchemaInitCreatesThenSkipsTables(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('durable:schema:init');
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('créée', $tester->getDisplay());

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('déjà présente', $tester->getDisplay());
    }
}
