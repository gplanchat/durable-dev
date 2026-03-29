<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Levée par {@see \Gplanchat\Durable\ExecutionContext::continueAsNew()} pour terminer le run courant
 * et enchaîner un nouveau run avec un historique vierge (même logique métier Temporal continue-as-new).
 *
 * Le moteur append {@see \Gplanchat\Durable\Event\WorkflowContinuedAsNew} puis propage cette exception.
 */
final class ContinueAsNewRequested extends \RuntimeException
{
    public function __construct(
        public readonly string $workflowType,
        public readonly array $payload,
    ) {
        parent::__construct(\sprintf('Continue as new: workflow type %s', $workflowType));
    }
}
