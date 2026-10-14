<?php

declare(strict_types=1);

namespace App\Tests\Functional\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\WorkflowRepository;

/**
 * @extends WorkflowRepository<ApprovalWorkflow>
 */
#[AsWorkflowRepository(ApprovalWorkflow::class)]
final class ApprovalRepository extends WorkflowRepository {}
