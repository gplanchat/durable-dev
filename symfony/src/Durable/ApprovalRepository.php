<?php

declare(strict_types=1);

namespace App\Durable;

use App\Durable\Workflow\ApprovalWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\WorkflowRepository;

/**
 * The entry point of the application to the executions of {@see ApprovalWorkflow}.
 *
 * @extends WorkflowRepository<ApprovalWorkflow>
 */
#[AsWorkflowRepository(ApprovalWorkflow::class)]
final class ApprovalRepository extends WorkflowRepository {}
