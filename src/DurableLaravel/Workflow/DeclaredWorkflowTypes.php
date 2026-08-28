<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Workflow;

use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * Le registre des workflows, plus la seule chose que le cœur ne peut pas dire à sa place.
 *
 * `WorkflowRegistry::getHandler()` échoue sur « Unknown workflow type: X », ce qui nomme le type et
 * s'arrête là. Sous Laravel, la question suivante du lecteur a une réponse — *où* déclare-t-on un
 * type ? — et c'est ce paquet qui la connaît, pas le cœur, qui ignore jusqu'à l'existence d'un
 * `config/durable.php`.
 *
 * Un message qui nomme la panne sans nommer le remède fait ouvrir le code d'un paquet installé.
 */
final class DeclaredWorkflowTypes
{
    /** @param list<class-string> $declared les classes que `config/durable.php` nomme */
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
