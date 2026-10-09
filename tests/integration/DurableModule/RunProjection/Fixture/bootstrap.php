<?php

declare(strict_types=1);

/*
 * Loads Magento's database adapter without Magento's application: `magento/vendor/autoload.php`
 * needs the installed shop (`app/etc/NonComposerComponentRegistration.php`), the adapter does not.
 * `DURABLE_TEST_MYSQL` names the server: `user:password@host:port/database`.
 */

use Magento\Framework\App\ObjectManager;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Logger\Quiet;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\Declaration\Schema\Dto\Factories\Table as DtoFactoriesTable;
use Magento\Framework\Setup\SchemaListener;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\StringUtils;

$root = \dirname(__DIR__, 5);
$vendor = $root . '/magento/vendor';

if (!is_file($vendor . '/composer/autoload_psr4.php')) {
    throw new RuntimeException('magento/vendor is not installed');
}

require_once $vendor . '/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require $vendor . '/composer/autoload_psr4.php' as $namespace => $paths) {
    $loader->setPsr4($namespace, $paths);
}
$loader->addClassMap(require $vendor . '/composer/autoload_classmap.php');
foreach (glob($vendor . '/mage-os/zend-*/library') ?: [] as $library) {
    $loader->add('Zend_', $library);
}
$loader->addPsr4('Gplanchat\\DurableModule\\', $root . '/src/DurableModule/', true);
$loader->register();

function durable_test_connection(): AdapterInterface
{
    $dsn = parse_url('mysql://' . (getenv('DURABLE_TEST_MYSQL') ?: ''));
    if (!isset($dsn['host'], $dsn['port'], $dsn['user'], $dsn['pass'], $dsn['path'])) {
        throw new RuntimeException('DURABLE_TEST_MYSQL is not set: user:password@host:port/database');
    }

    // The adapter reads the default charset from the setup DTO, which needs the object manager.
    $charsets = new class extends DtoFactoriesTable {
        public function __construct() {}

        public function getDefaultCharset(): string
        {
            return 'utf8mb4';
        }

        public function getDefaultCollation(): string
        {
            return 'utf8mb4_general_ci';
        }
    };

    // `createTable()`, `dropTable()`, `addColumn()` and `modifyColumn()` tell the setup's schema listener, which comes from the object manager.
    $silent = new class extends SchemaListener {
        public function __construct() {}

        public function createTable(Table $table): void {}

        public function dropTable($tableName): void {}

        public function addColumn($tableName, $columnName, $definition, $primaryKeyName = 'PRIMARY', $onCreate = null): void {}

        public function modifyColumn($tableName, $columnName, $definition): void {}
    };

    // DDL objects and the adapter ask the object manager for these two, and for nothing else.
    ObjectManager::setInstance(new class ($charsets, $silent) implements ObjectManagerInterface {
        public function __construct(private readonly object $charsets, private readonly object $silent) {}

        public function create($type, array $arguments = []): object
        {
            return $this->get($type);
        }

        public function get($type): object
        {
            return SchemaListener::class === $type ? $this->silent : $this->charsets;
        }

        public function configure(array $configuration): void {}
    });

    return new Mysql(
        new StringUtils(),
        new DateTime(),
        new Quiet(),
        new SelectFactory(new SelectRenderer([])),
        [
            'host' => $dsn['host'] . ':' . $dsn['port'],
            'dbname' => ltrim($dsn['path'], '/'),
            'username' => $dsn['user'],
            'password' => $dsn['pass'],
        ],
        new Json(),
        $charsets,
    );
}
