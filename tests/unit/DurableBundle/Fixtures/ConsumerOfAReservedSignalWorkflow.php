<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\RepositoryFor;
use Gplanchat\Durable\WorkflowRepository;

final readonly class ConsumerOfAReservedSignalWorkflow
{
    /**
     * @param WorkflowRepository<WorkflowWithAReservedSignal> $orders
     */
    public function __construct(
        #[RepositoryFor(WorkflowWithAReservedSignal::class)]
        public WorkflowRepository $orders,
    ) {}
}
