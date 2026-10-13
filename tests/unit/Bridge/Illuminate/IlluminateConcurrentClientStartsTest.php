<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use unit\Bridge\ConcurrentClientStartsTestCase;
use unit\Bridge\SqlTestDatabase;

final class IlluminateConcurrentClientStartsTest extends ConcurrentClientStartsTestCase
{
    protected function twoStoresOverOneDatabase(): array
    {
        $connection = SqlTestDatabase::illuminate();
        $schema = new DurableSchema($connection);

        return [new IlluminateWorkflowMetadataStore($connection, $schema), new IlluminateWorkflowMetadataStore($connection, $schema)];
    }
}
