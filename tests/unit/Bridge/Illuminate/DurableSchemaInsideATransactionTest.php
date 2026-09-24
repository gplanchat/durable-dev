<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Schema\DurableSchemaMissing;
use PHPUnit\Framework\TestCase;
use unit\Bridge\SqlTestDatabase;

/**
 * The same refusal as the DBAL bridge (#339): inside `DB::transaction()`, MySQL would commit the
 * open transaction on the `CREATE TABLE`, and Laravel's own commit would then fail.
 */
final class DurableSchemaInsideATransactionTest extends TestCase
{
    public function testAutoCreationRefusesInsideATransaction(): void
    {
        $connection = SqlTestDatabase::illuminate();
        $schema = new DurableSchema($connection);
        $connection->beginTransaction();

        try {
            $schema->ensure();
            self::fail('ensure() was supposed to refuse inside an open transaction.');
        } catch (DurableSchemaMissing $refusal) {
            self::assertStringContainsString('php artisan migrate', $refusal->getMessage());
        }

        self::assertSame(1, $connection->transactionLevel(), 'the caller keeps its transaction');
        $connection->rollBack();

        $schema->ensure();
        self::assertTrue($connection->getSchemaBuilder()->hasTable('durable_events'), 'the next attempt, outside, creates them');
    }
}
