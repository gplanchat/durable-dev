<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Testing\WorkflowMetadataStoreConformanceTestCase;

/**
 * @see DUR041
 * @see DUR030
 */
final class DbalWorkflowMetadataStoreConformanceTest extends WorkflowMetadataStoreConformanceTestCase
{
    protected function createMetadataStore(): WorkflowMetadataStore
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        return new DbalWorkflowMetadataStore($connection, new DurableSchema($connection));
    }
}
