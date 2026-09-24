<?php

declare(strict_types=1);

namespace unit\Bridge;

use Gplanchat\Bridge\Dbal\Schema\DurableSchema as DbalSchema;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Bridge\Illuminate\Schema\DurableSchema as IlluminateSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;

/**
 * The port only saves an array, but the row can be written by something else: a migration, a hand
 * fix, an older version. A payload that decodes to anything but an array reads back as an empty
 * one, on both bridges alike (#362). The conformance suite cannot state this: it only writes
 * through the port.
 */
final class AStoredPayloadThatIsNotAnObjectReadsAsEmptyTest extends TestCase
{
    public function testOnDbal(): void
    {
        $connection = SqlTestDatabase::dbal();
        $store = new DbalWorkflowMetadataStore($connection, new DbalSchema($connection));
        $store->save('exec-1', 'App\\OrderWorkflow', ['order' => 42]);
        $connection->update('durable_workflow_metadata', ['payload' => '"not an object"'], ['execution_id' => 'exec-1']);

        self::assertSame([], $store->get('exec-1')['payload'] ?? null);
    }

    public function testOnIlluminate(): void
    {
        $connection = SqlTestDatabase::illuminate();
        $store = new IlluminateWorkflowMetadataStore($connection, new IlluminateSchema($connection));
        $store->save('exec-1', 'App\\OrderWorkflow', ['order' => 42]);
        $connection->table('durable_workflow_metadata')->where('execution_id', 'exec-1')->update(['payload' => '"not an object"']);

        self::assertSame([], $store->get('exec-1')['payload'] ?? null);
    }
}
