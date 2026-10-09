<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * The journal's connection on Magento's own DB layer (DUR056 decisions 2 to 4).
 *
 * `env.php` declares it: `resource/durable` names a connection of `db/connection`, a dedicated
 * `db/connection/durable` recommended. It is resolved with `getConnectionByName()`, never with
 * `getConnection('durable')`: without `resource/durable`, that call returns the shop's connection,
 * and the journal would land in the shop's database with no error (spike #709).
 *
 * ponytail: not wired yet. Which configuration selects the SQL backend is open; #754 assembles it.
 */
final class JournalConnectionResolver
{
    private const RESOURCE_CONFIG_PATH = 'resource/durable/connection';
    private const TEMPORAL_DSN_CONFIG_PATH = 'durable/temporal/dsn';
    private const HOW_TO_DECLARE = "Declare the journal's own connection under db/connection/durable in app/etc/env.php, and set resource/durable to ['connection' => 'durable'].";

    private ?AdapterInterface $adapter = null;

    public function __construct(
        private readonly ResourceConnection $connections,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolve(): AdapterInterface
    {
        return $this->adapter ??= $this->connect();
    }

    private function connect(): AdapterInterface
    {
        $name = $this->deploymentConfig->get(self::RESOURCE_CONFIG_PATH);
        $dsn = $this->deploymentConfig->get(self::TEMPORAL_DSN_CONFIG_PATH);

        if (null !== $name && null !== $dsn && '' !== $dsn) {
            throw new \RuntimeException(\sprintf('app/etc/env.php sets both resource/durable and %s. The journal lives either in a database or on a Temporal cluster: remove one of the two keys.', self::TEMPORAL_DSN_CONFIG_PATH));
        }

        if (!\is_string($name) || '' === $name) {
            throw new \RuntimeException('app/etc/env.php does not declare resource/durable, so the journal has no connection. ' . self::HOW_TO_DECLARE);
        }

        if (null === $this->deploymentConfig->get('db/connection/' . $name)) {
            throw new \RuntimeException(\sprintf('resource/durable names the connection "%s", but app/etc/env.php declares no db/connection/%s. Declare it, or point resource/durable at a declared connection.', $name, $name));
        }

        if (ResourceConnection::DEFAULT_CONNECTION === $name) {
            $this->logger->warning(\sprintf('resource/durable names the shop\'s connection "%s": the journal shares the shop\'s database and transactions, and a workflow started inside a shop transaction fails on the nested transaction. %s', $name, self::HOW_TO_DECLARE));
        }

        return $this->connections->getConnectionByName($name);
    }
}
