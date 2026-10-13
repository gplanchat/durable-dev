<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\ReopensCompletedWorkflowMetadata;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Testing\ReopensCompletedWorkflowMetadataConformanceTestCase;

/** @see DUR041 */
final class InMemoryWorkflowMetadataStoreConformanceTest extends ReopensCompletedWorkflowMetadataConformanceTestCase
{
    protected function createMetadataStore(): WorkflowMetadataStore&ReopensCompletedWorkflowMetadata
    {
        return new InMemoryWorkflowMetadataStore();
    }
}
