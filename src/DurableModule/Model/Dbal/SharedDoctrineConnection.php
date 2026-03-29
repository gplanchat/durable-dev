<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Model\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Magento\Framework\App\ResourceConnection;

/**
 * Connexion Doctrine DBAL partagée, construite à partir du PDO Magento.
 */
final class SharedDoctrineConnection
{
    private ?Connection $connection = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function get(): Connection
    {
        if (null !== $this->connection) {
            return $this->connection;
        }

        $adapter = $this->resourceConnection->getConnection();
        $pdo = $adapter->getConnection();
        if (!$pdo instanceof \PDO) {
            throw new \RuntimeException('Magento DB adapter must expose a PDO connection for Durable DBAL mode.');
        }

        $this->connection = DriverManager::getConnection(['pdo' => $pdo]);

        return $this->connection;
    }
}
