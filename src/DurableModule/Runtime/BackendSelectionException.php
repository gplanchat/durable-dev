<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

/**
 * The module cannot tell which backend to assemble, or has no way to run an execution.
 *
 * A backend is one thing: the database backend records in a database, the Temporal backend on a
 * cluster, the memory backend in the process. The factory assembles one of them and never a part
 * of one beside a part of another, so two declared backends are an error to fix in `env.php`.
 */
final class BackendSelectionException extends \RuntimeException
{
    private function __construct(string $message, public readonly ?string $first = null, public readonly ?string $second = null)
    {
        parent::__construct($message);
    }

    /** @param string $first the backend `env.php` declares first (`database`), $second the other (`temporal`) */
    public static function bothDeclared(string $first, string $second): self
    {
        return new self(
            'app/etc/env.php sets both resource/durable and durable/temporal/dsn. The journal lives either in a database or on a Temporal cluster: remove one of the two keys.',
            $first,
            $second,
        );
    }

    public static function nothingToRunWith(): self
    {
        return new self('This runtime has neither an in-process runner nor a backend to start executions on. Build it through RuntimeFactory::create().');
    }
}
