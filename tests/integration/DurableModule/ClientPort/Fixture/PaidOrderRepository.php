<?php

declare(strict_types=1);

namespace integration\DurableModule\ClientPort\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\WorkflowRepository;

/** @extends WorkflowRepository<PaidOrderWorkflow> */
#[AsWorkflowRepository(PaidOrderWorkflow::class)]
final class PaidOrderRepository extends WorkflowRepository {}
