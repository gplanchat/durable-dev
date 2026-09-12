<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

/**
 * An execution was started for a workflow type the module was never given.
 *
 * The refusal is the mechanism. Without it, declaration declares nothing: `run()` registered the
 * class on the fly, so any class ran, whether it was in `di.xml` or not — and the omission only
 * showed in production, on the one machine where the workflow had not been deployed.
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
            RuntimeFactory::class,
        ));
    }
}
