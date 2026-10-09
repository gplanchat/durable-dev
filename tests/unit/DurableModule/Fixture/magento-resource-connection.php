<?php

declare(strict_types=1);

/*
 * The three Magento types the journal's connection resolver stands on, reduced to what it calls,
 * with Magento's own untyped signatures. Magento is not installed in the root suite; declared only
 * when absent, so a Magento checkout keeps its own.
 */

namespace Magento\Framework\DB\Adapter;

if (!interface_exists(AdapterInterface::class)) {
    interface AdapterInterface {}
}

namespace Magento\Framework\App;

use Magento\Framework\DB\Adapter\AdapterInterface;

if (!class_exists(DeploymentConfig::class)) {
    class DeploymentConfig
    {
        /**
         * @param string|null $key
         * @param mixed       $defaultValue
         *
         * @return mixed
         */
        public function get($key = null, $defaultValue = null)
        {
            return $defaultValue;
        }
    }
}

if (!class_exists(ResourceConnection::class)) {
    class ResourceConnection
    {
        public const DEFAULT_CONNECTION = 'default';

        /**
         * @param string $resourceName
         *
         * @return AdapterInterface
         */
        public function getConnection($resourceName = self::DEFAULT_CONNECTION)
        {
            throw new \LogicException('Stubbed in tests.');
        }

        /**
         * @param string $connectionName
         *
         * @return AdapterInterface
         */
        public function getConnectionByName($connectionName)
        {
            throw new \LogicException('Stubbed in tests.');
        }
    }
}
