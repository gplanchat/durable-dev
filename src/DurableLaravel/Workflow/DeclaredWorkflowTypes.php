<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Workflow;

use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * The workflow registry, plus the one thing the core cannot say in its place.
 *
 * `WorkflowRegistry::getHandler()` fails on "Unknown workflow type: X", which names the type and
 * stops there. Under Laravel, the reader's next question has an answer — *where* does one declare
 * a type? — and it is this package that knows it, not the core, which does not even know that a
 * `config/durable.php` exists.
 *
 * A message that names the failure without naming the remedy makes someone open the code of an
 * installed package.
 */
final class DeclaredWorkflowTypes
{
    /** @param list<class-string> $declared the classes `config/durable.php` names */
    public function __construct(
        private readonly WorkflowRegistry $registry,
        private readonly array $declared = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @return callable(WorkflowEnvironment): mixed
     */
    public function handlerFor(string $workflowType, array $payload): callable
    {
        if (!$this->registry->has($workflowType)) {
            throw new \InvalidArgumentException(\sprintf(
                'Durable: no workflow declared for type "%s". Add its class to the "workflows" key '
                . 'of config/durable.php (publish it with `php artisan vendor:publish '
                . '--tag=durable-config`). Declared: %s.',
                $workflowType,
                $this->declared === [] ? 'none' : '"' . implode('", "', $this->declared) . '"',
            ));
        }

        return $this->registry->getHandler($workflowType, $payload);
    }
}
