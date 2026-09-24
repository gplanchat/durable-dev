<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Schema;

use Gplanchat\Durable\Exception\ExceptionInterface;

/**
 * Durable's tables are missing and cannot be created where the write happens.
 */
final class DurableSchemaMissing extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param list<string> $tables
     */
    public static function insideTransaction(array $tables): self
    {
        return new self(\sprintf(
            'Durable\'s tables %s are missing, and a transaction is open: creating them here would commit it implicitly on MySQL. Run "bin/console durable:setup" once (or let migrations create them) before the first write.',
            implode(', ', $tables),
        ));
    }
}
