<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use PHPUnit\Framework\TestCase;

/**
 * "A catalog is registered" and "the backend answers" are two distinct questions, and the page
 * was asking only the first: an unreachable database gave a dashboard that claimed to be
 * connected.
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
        // A SQLite file in a directory that does not exist: the connection is lazy, so the
        // failure only happens on the first statement — exactly the case of a database that went
        // down en route.
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
