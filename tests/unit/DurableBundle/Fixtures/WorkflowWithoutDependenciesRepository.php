<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\WorkflowRepository;

/**
 * @extends WorkflowRepository<WorkflowWithoutDependencies>
 */
#[AsWorkflowRepository(WorkflowWithoutDependencies::class)]
final class WorkflowWithoutDependenciesRepository extends WorkflowRepository {}
