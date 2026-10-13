<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Testing\WorkflowMetadataStoreConformanceTestCase;

/** @see DUR041 */
final class InMemoryWorkflowMetadataStoreConformanceTest extends WorkflowMetadataStoreConformanceTestCase
{
    protected function createMetadataStore(): WorkflowMetadataStore
    {
        return new InMemoryWorkflowMetadataStore();
    }
}
