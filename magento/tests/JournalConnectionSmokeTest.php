<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use PHPUnit\Framework\TestCase;

/**
 * Proves the harness before any store exists (#747): inside the Magento bootstrap, the journal's
 * adapter answers on the journal's database, never on the shop's.
 */
final class JournalConnectionSmokeTest extends TestCase
{
    public function testTheJournalAdapterAnswersOnTheJournalsDatabase(): void
    {
        $config = JournalHarness::deploymentConfig();
        $name = $config->get('resource/durable/connection');
        $journal = $config->get('db/connection/' . $name . '/dbname');
        $shop = $config->get('db/connection/default/dbname');

        self::assertNotSame($shop, $journal, 'the journal needs a second database');
        self::assertSame($journal, JournalHarness::adapter()->fetchOne('SELECT DATABASE()'));
    }

    public function testEveryDurableTableExistsAndIsEmpty(): void
    {
        $adapter = JournalHarness::adapter();

        self::assertCount(6, $adapter->getTables('durable\_%'));
        self::assertSame('0', (string) $adapter->fetchOne('SELECT COUNT(*) FROM durable_events'));
    }
}
