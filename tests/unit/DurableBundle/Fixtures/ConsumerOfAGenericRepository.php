<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\RepositoryFor;
use Gplanchat\Durable\WorkflowRepository;

final readonly class ConsumerOfAGenericRepository
{
    /**
     * @param WorkflowRepository<WorkflowWithoutDependencies> $orders
     * @param WorkflowRepository<WorkflowWithoutDependencies> $sameWorkflow
     */
    public function __construct(
        #[RepositoryFor(WorkflowWithoutDependencies::class)]
        public WorkflowRepository $orders,
        #[RepositoryFor(WorkflowWithoutDependencies::class)]
        public WorkflowRepository $sameWorkflow,
        public ?string $untouched = null,
    ) {}
}
