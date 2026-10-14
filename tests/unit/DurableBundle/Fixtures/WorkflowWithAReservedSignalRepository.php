<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\WorkflowRepository;

/**
 * @extends WorkflowRepository<WorkflowWithAReservedSignal>
 */
#[AsWorkflowRepository(WorkflowWithAReservedSignal::class)]
final class WorkflowWithAReservedSignalRepository extends WorkflowRepository {}
