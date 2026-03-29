<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Dbal;

use Doctrine\DBAL\DriverManager;
use Gplanchat\Durable\Store\DbalChildWorkflowParentLinkStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(DbalChildWorkflowParentLinkStore::class)]
final class DbalChildWorkflowParentLinkStoreTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private DbalChildWorkflowParentLinkStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->store = new DbalChildWorkflowParentLinkStore($this->connection, 'durable_child_parent');
        $this->store->createSchema();
    }

    #[Test]
    public function linkGetUnlinkRoundTrip(): void
    {
        $this->store->link('child-a', 'parent-1');
        self::assertSame('parent-1', $this->store->getParentExecutionId('child-a'));

        $this->store->unlink('child-a');
        self::assertNull($this->store->getParentExecutionId('child-a'));
    }

    #[Test]
    public function linkReplacesPreviousParentForSameChild(): void
    {
        $this->store->link('child-x', 'parent-old');
        $this->store->link('child-x', 'parent-new');
        self::assertSame('parent-new', $this->store->getParentExecutionId('child-x'));
    }
}
