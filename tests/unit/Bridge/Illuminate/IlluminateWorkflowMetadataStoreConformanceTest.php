<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use Gplanchat\Durable\Store\ReopensCompletedWorkflowMetadata;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Testing\ReopensCompletedWorkflowMetadataConformanceTestCase;
use unit\Bridge\SqlTestDatabase;

/**
 * `illuminate/database` is usable with no Laravel application around it — that is what Capsule is,
 * and the adapters touch nothing but a `Connection`. No container, no service provider:
 * the surface is the one a real application would hand them.
 *
 * @see DUR041
 */
final class IlluminateWorkflowMetadataStoreConformanceTest extends ReopensCompletedWorkflowMetadataConformanceTestCase
{
    protected function createMetadataStore(): WorkflowMetadataStore&ReopensCompletedWorkflowMetadata
    {
        $connection = SqlTestDatabase::illuminate();

        return new IlluminateWorkflowMetadataStore($connection, new DurableSchema($connection));
    }
}
