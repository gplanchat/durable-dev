<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\DurableModule\Runtime\JournalConnectionResolver;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * The extension point of the bench's conformance suites (#747).
 *
 * A store ticket adds one subclass of a shared case from `src/Durable/Testing` in this directory
 * and builds its store on {@see adapter()}:
 *
 *     protected function createEventStore(): EventStoreInterface
 *     {
 *         return new MagentoEventStore(JournalHarness::adapter());
 *     }
 *
 * Nothing else is needed: the bootstrap, the connection and the schema are done here.
 */
final class JournalHarness
{
    private static ?ObjectManagerInterface $objectManager = null;

    /**
     * The journal's adapter, with every Durable table created and empty.
     *
     * It throws, so the test errors and never skips, when `app/etc/env.php` does not declare
     * `resource/durable` (the resolver says what to declare), or declares it on the shop's connection.
     */
    public static function adapter(): AdapterInterface
    {
        $adapter = self::objectManager()->get(JournalConnectionResolver::class)->resolve();
        if (self::deploymentConfig()->get('resource/durable/connection') === 'default') {
            throw new \RuntimeException("The harness truncates the journal's tables: it will not run on the shop's connection. Declare db/connection/durable on a second database.");
        }

        (new JournalSchema($adapter))->setup();
        foreach ($adapter->getTables('durable\_%') as $table) {
            $adapter->truncateTable($table);
        }

        return $adapter;
    }

    public static function deploymentConfig(): DeploymentConfig
    {
        return self::objectManager()->get(DeploymentConfig::class);
    }

    private static function objectManager(): ObjectManagerInterface
    {
        return self::$objectManager ??= Bootstrap::create(BP, $_SERVER)->getObjectManager();
    }
}
