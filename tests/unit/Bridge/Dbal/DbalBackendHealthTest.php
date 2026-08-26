<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use PHPUnit\Framework\TestCase;

/**
 * « Un catalogue est enregistré » et « le backend répond » sont deux questions distinctes, et la
 * page ne posait que la première : une base injoignable donnait un tableau de bord qui se
 * prétendait connecté.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/tasks.md §4.4
 */
final class DbalBackendHealthTest extends TestCase
{
    public function testAReachableDatabaseIsReportedReachable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $health = $this->catalog($connection)->checkHealth();

        self::assertTrue($health->reachable);
        self::assertNotSame('', $health->backend);
        self::assertInstanceOf(\DateTimeImmutable::class, $health->checkedAt);
    }

    public function testAnUnreachableDatabaseIsReportedUnreachableRatherThanThrowing(): void
    {
        // Un fichier SQLite dans un répertoire qui n'existe pas : la connexion est paresseuse, donc
        // l'échec ne survient qu'au premier ordre — exactement le cas d'une base tombée en route.
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => '/nonexistent-directory-for-tests/durable.sqlite',
        ]);

        $health = $this->catalog($connection)->checkHealth();

        self::assertFalse($health->reachable);
        self::assertNotSame('', $health->message);
        self::assertInstanceOf(\DateTimeImmutable::class, $health->checkedAt);
    }

    public function testTheHealthNamesTheBackendItProbed(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertStringContainsStringIgnoringCase('sql', $this->catalog($connection)->checkHealth()->backend);
    }

    private function catalog(Connection $connection): DbalWorkflowRunCatalog
    {
        return new DbalWorkflowRunCatalog($connection, new DurableSchema($connection));
    }
}
