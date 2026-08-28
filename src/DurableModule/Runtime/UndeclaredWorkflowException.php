<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Runtime;

/**
 * Une exécution a été lancée pour un type de workflow que le module n'a jamais reçu.
 *
 * Le refus est le mécanisme. Sans lui, la déclaration ne déclare rien : `run()` enregistrait la
 * classe au vol, donc n'importe laquelle tournait, qu'elle fût dans `di.xml` ou non — et l'oubli
 * ne se voyait qu'en production, sur la seule machine où le workflow n'a pas été déployé.
 */
final class UndeclaredWorkflowException extends \RuntimeException
{
    /**
     * @param class-string $workflowClass
     */
    public static function forClass(string $workflowClass): self
    {
        return new self(\sprintf(
            'The workflow %s was never declared to the module. Add it to the workflowClasses argument of %s in your di.xml, beside the ones already there.',
            $workflowClass,
            InMemoryRuntimeFactory::class,
        ));
    }
}
