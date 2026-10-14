<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\WorkflowRepository;

/**
 * Serves a workflow that a test leaves out of the container.
 *
 * @extends WorkflowRepository<WorkflowWithEnvironment>
 */
#[AsWorkflowRepository(WorkflowWithEnvironment::class)]
final class WorkflowWithEnvironmentRepository extends WorkflowRepository {}
