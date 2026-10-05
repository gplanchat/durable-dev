<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Schema;

/**
 * A store found a Durable table missing. No store issues DDL (DUR056): the table comes from
 * `bin/magento durable:setup`.
 */
final class JournalTableMissing extends \RuntimeException
{
    public static function named(string $table): self
    {
        return new self(\sprintf('The table "%s" does not exist on the journal\'s connection. Run bin/magento durable:setup to create the Durable tables.', $table));
    }
}
