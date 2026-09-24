<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use PHPUnit\Framework\TestCase;

/**
 * An application whose schema assets filter rejects `durable_*` keeps Doctrine's tooling away from
 * Durable's tables — and DBAL applies that filter to `tablesExist()` too. Durable must still see its
 * own tables, or they look missing forever: `durable:setup` fails on its second run, `ensure()`
 * recreates what exists, and pickups are never recorded (#339).
 *
 * The tables are created on one connection, then probed from a second one carrying the filter.
 */
final class DurableSchemaAssetsFilterTest extends TestCase
{
    private string $path;
    private Connection $filtered;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'durable-filter-');
        (new DurableSchema(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path])))->setup();

        $this->filtered = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path]);
        $this->filtered->getConfiguration()->setSchemaAssetsFilter(
            static fn(string|\Doctrine\DBAL\Schema\AbstractAsset $asset): bool => !str_starts_with(\is_string($asset) ? $asset : $asset->getName(), 'durable_'),
        );
    }

    protected function tearDown(): void
    {
        $this->filtered->close();
        @unlink($this->path);
    }

    public function testSetupRunsTwice(): void
    {
        (new DurableSchema($this->filtered))->setup();

        self::assertFalse(
            ($this->filtered->getConfiguration()->getSchemaAssetsFilter())('durable_events'),
            'the application keeps its filter once Durable has looked',
        );
    }

    public function testEnsureFindsTheTables(): void
    {
        (new DurableSchema($this->filtered))->ensure();

        $this->addToAssertionCount(1);
    }

    public function testEnsureInsideATransactionFindsTheTables(): void
    {
        $this->filtered->beginTransaction();

        (new DurableSchema($this->filtered))->ensure();

        self::assertTrue($this->filtered->isTransactionActive());
    }

    public function testThePickupColumnIsFound(): void
    {
        self::assertTrue((new DurableSchema($this->filtered))->runsTableTracksPickup());
    }
}
