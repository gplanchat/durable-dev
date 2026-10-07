<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\DurableModule\Runtime\JournalConnectionResolver;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Store\MagentoEventStore;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use unit\DurableModule\Fixture\NexusBillingHandler;
use unit\DurableModule\Fixture\NexusChargeWorkflow;

require_once __DIR__ . '/Fixture/magento-resource-connection.php';
require_once __DIR__ . '/Fixture/magento-ddl-table.php';

/**
 * `resource/durable` in `env.php` selects the SQL backend (#754, DUR056): the factory builds the
 * Magento-adapter stores on the connection that key names, and refuses Nexus by name (DUR051).
 */
final class TheDatabaseBackendIsAssembledTest extends TestCase
{
    public function testTheEventStoreIsTheMagentoOneOnTheConnectionResourceDurableNames(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $factory = $this->factory(['resource/durable/connection' => 'durable', 'db/connection/durable' => ['host' => 'journal']], $adapter);

        $backend = $factory->database();

        self::assertInstanceOf(MagentoEventStore::class, $backend->events);
        self::assertSame($adapter, $backend->connection);
        self::assertInstanceOf(FencedEventStoreInterface::class, $factory->create()->eventStore());
    }

    /** An optional constructor argument is not autowired: without this line the container leaves it null and the backend is never selected. */
    public function testDiXmlHandsTheConnectionResolverToTheFactory(): void
    {
        $di = simplexml_load_file(__DIR__ . '/../../../src/DurableModule/etc/di.xml');
        self::assertNotFalse($di);

        $argument = $di->xpath(\sprintf('//type[@name="%s"]/arguments/argument[@name="journalConnection"]', RuntimeFactory::class));
        self::assertSame(JournalConnectionResolver::class, trim((string) ($argument[0] ?? '')));
    }

    public function testWithoutResourceDurableThereIsNoDatabaseBackend(): void
    {
        $factory = $this->factory([], $this->createStub(AdapterInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resource/durable');

        $factory->database();
    }

    public function testResourceDurableAndATemporalDsnTogetherFailNamingBothKeys(): void
    {
        $factory = $this->factory([
            'resource/durable/connection' => 'durable',
            'db/connection/durable' => ['host' => 'journal'],
            'durable/temporal/dsn' => 'temporal://127.0.0.1:7233',
        ], $this->createStub(AdapterInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('{resource/durable.*durable/temporal/dsn}');

        $factory->create();
    }

    public function testRegisteringANexusHandlerOnTheDatabaseBackendIsRefusedByName(): void
    {
        $factory = $this->factory(
            ['resource/durable/connection' => 'durable', 'db/connection/durable' => ['host' => 'journal']],
            $this->createStub(AdapterInterface::class),
            workflowClasses: [NexusChargeWorkflow::class],
            nexusHandlers: [new NexusBillingHandler()],
        );

        $this->expectException(NexusUnsupportedByBackendException::class);
        $this->expectExceptionMessage('database');

        $factory->nexusRegistry();
    }

    /**
     * @param array<string, mixed> $env `env.php` flattened by path, as `DeploymentConfig::get()` reads it
     * @param list<class-string>   $workflowClasses
     * @param array<array-key, object> $nexusHandlers
     */
    private function factory(array $env, AdapterInterface $adapter, array $workflowClasses = [], array $nexusHandlers = []): RuntimeFactory
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(static fn($key = null, $default = null): mixed => $env[$key] ?? $default);

        $connections = $this->createStub(ResourceConnection::class);
        $connections->method('getConnectionByName')->willReturn($adapter);

        return new RuntimeFactory(
            workflowClasses: $workflowClasses,
            deploymentConfig: $config,
            nexusHandlers: $nexusHandlers,
            journalConnection: new JournalConnectionResolver($connections, $config, new NullLogger()),
        );
    }
}
