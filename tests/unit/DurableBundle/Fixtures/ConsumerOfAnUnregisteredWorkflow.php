<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\RepositoryFor;
use Gplanchat\Durable\WorkflowRepository;

final readonly class ConsumerOfAnUnregisteredWorkflow
{
    /**
     * @param WorkflowRepository<WorkflowWithEnvironment> $orders
     */
    public function __construct(
        #[RepositoryFor(WorkflowWithEnvironment::class)]
        public WorkflowRepository $orders,
    ) {}
}
