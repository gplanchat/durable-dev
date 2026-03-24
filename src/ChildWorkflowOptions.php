<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Options pour {@see ExecutionContext::executeChildWorkflow()}.
 */
final readonly class ChildWorkflowOptions
{
    public function __construct(
        /**
         * Identifiant d’exécution enfant (clé du journal enfant). Si null, un UUID est généré.
         */
        public ?string $workflowId = null,
        public ParentClosePolicy $parentClosePolicy = ParentClosePolicy::Terminate,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }
}
