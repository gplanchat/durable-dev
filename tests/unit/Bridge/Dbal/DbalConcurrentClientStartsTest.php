<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use unit\Bridge\ConcurrentClientStartsTestCase;
use unit\Bridge\SqlTestDatabase;

final class DbalConcurrentClientStartsTest extends ConcurrentClientStartsTestCase
{
    protected function twoStoresOverOneDatabase(): array
    {
        $connection = SqlTestDatabase::dbal();
        $schema = new DurableSchema($connection);

        return [new DbalWorkflowMetadataStore($connection, $schema), new DbalWorkflowMetadataStore($connection, $schema)];
    }
}
