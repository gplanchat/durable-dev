<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Durable\Store\ReopensCompletedWorkflowMetadata;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Testing\ReopensCompletedWorkflowMetadataConformanceTestCase;
use unit\Bridge\SqlTestDatabase;

/**
 * @see DUR041
 * @see DUR030
 */
final class DbalWorkflowMetadataStoreConformanceTest extends ReopensCompletedWorkflowMetadataConformanceTestCase
{
    protected function createMetadataStore(): WorkflowMetadataStore&ReopensCompletedWorkflowMetadata
    {
        $connection = SqlTestDatabase::dbal();

        return new DbalWorkflowMetadataStore($connection, new DurableSchema($connection));
    }
}
