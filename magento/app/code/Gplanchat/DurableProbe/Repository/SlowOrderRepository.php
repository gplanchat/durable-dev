<?php

declare(strict_types=1);

namespace Gplanchat\DurableProbe\Repository;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\WorkflowRepository;
use Gplanchat\DurableProbe\Workflow\SlowOrderWorkflow;

/**
 * The declared repository of {@see SlowOrderWorkflow}. The ObjectManager builds it from the two
 * preferences of the module's `di.xml`; there is nothing to declare for it.
 *
 * @extends WorkflowRepository<SlowOrderWorkflow>
 */
#[AsWorkflowRepository(SlowOrderWorkflow::class)]
final class SlowOrderRepository extends WorkflowRepository {}
