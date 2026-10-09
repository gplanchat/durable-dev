<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Testing\ChildWorkflowParentLinkStoreConformanceTestCase;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Store\MagentoChildWorkflowParentLinkStore;

/**
 * The shared parent-link cases (DUR041) on the Magento adapter, on a second MySQL database (#751).
 */
final class MagentoChildWorkflowParentLinkStoreConformanceTest extends ChildWorkflowParentLinkStoreConformanceTestCase
{
    protected function createParentLinkStore(): ChildWorkflowParentLinkStoreInterface
    {
        $adapter = JournalHarness::adapter();

        return new MagentoChildWorkflowParentLinkStore($adapter, new JournalSchema($adapter));
    }
}
