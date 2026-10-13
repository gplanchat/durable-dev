<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A start under an execution id that already has an execution. The first execution is unchanged.
 */
final class WorkflowExecutionAlreadyStarted extends \RuntimeException implements ExceptionInterface
{
    public function __construct(public readonly string $executionId)
    {
        parent::__construct('A workflow execution already started under this id.');
    }
}
