<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A read-only pass, the one that answers a query, reached a command that the journal does not hold
 * yet. Nothing is written: the pass stops where the workflow would have acted.
 */
final class ReadOnlyPassRefusedWrite extends \LogicException implements ExceptionInterface
{
    public function __construct(public readonly string $command)
    {
        parent::__construct('A read-only pass cannot record a command.');
    }
}
