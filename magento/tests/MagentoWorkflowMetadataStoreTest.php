<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Testing\WorkflowMetadataStoreConformanceTestCase;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Store\MagentoWorkflowMetadataStore;

/**
 * The shared metadata-store cases, including insert-if-absent (#946), on the Magento adapter (#750).
 */
final class MagentoWorkflowMetadataStoreTest extends WorkflowMetadataStoreConformanceTestCase
{
    protected function createMetadataStore(): WorkflowMetadataStore
    {
        $adapter = JournalHarness::adapter();

        return new MagentoWorkflowMetadataStore($adapter, new JournalSchema($adapter));
    }
}
