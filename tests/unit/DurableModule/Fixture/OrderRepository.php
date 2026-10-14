<?php

declare(strict_types=1);

namespace unit\DurableModule\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\WorkflowRepository;

/** @extends WorkflowRepository<OrderWorkflow> */
#[AsWorkflowRepository(OrderWorkflow::class)]
final class OrderRepository extends WorkflowRepository {}
