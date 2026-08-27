<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Testing\ChildWorkflowParentLinkStoreConformanceTestCase;

/**
 * @see DUR041
 * @see DUR030
 */
final class DbalChildWorkflowParentLinkStoreConformanceTest extends ChildWorkflowParentLinkStoreConformanceTestCase
{
    protected function createParentLinkStore(): ChildWorkflowParentLinkStoreInterface
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        return new DbalChildWorkflowParentLinkStore($connection, new DurableSchema($connection));
    }
}
