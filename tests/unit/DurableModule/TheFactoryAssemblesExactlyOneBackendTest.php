<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\DurableModule\Runtime\BackendSelectionException;
use Gplanchat\DurableModule\Runtime\InProcessWorkflowResumeDispatcher;
use Gplanchat\DurableModule\Runtime\JournalConnectionResolver;
use Gplanchat\DurableModule\Runtime\MagentoRuntime;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Runtime\TableQueue\TableQueueWorkflowResumeDispatcher;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunCatalog;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/Fixture/magento-resource-connection.php';
require_once __DIR__ . '/Fixture/magento-ddl-table.php';

/**
 * A backend is one thing: the factory assembles the database backend, the Temporal one or the
 * memory one, and never a part of one beside a part of another (#754).
 */
final class TheFactoryAssemblesExactlyOneBackendTest extends TestCase
{
    private const DATABASE = ['resource/durable/connection' => 'durable', 'db/connection/durable' => ['host' => 'journal']];
    private const DSN = 'temporal://127.0.0.1:7233';

    public function testWithADatabaseTheCatalogAndTheJournalComeFromTheDatabaseStores(): void
    {
        $factory = $this->factory(self::DATABASE);

        $runtime = $factory->create();

        self::assertInstanceOf(MagentoWorkflowRunCatalog::class, $factory->catalog());
        self::assertSame($factory->database()->catalog, $factory->catalog());
        self::assertSame($factory->database()->eventStore, $runtime->eventStore());
        self::assertNotInstanceOf(InMemoryEventStore::class, $runtime->eventStore());
    }

    public function testWithADatabaseTheRuntimeHoldsNoInMemoryRunner(): void
    {
        $runtime = $this->factory(self::DATABASE)->create();

        self::assertNull((new \ReflectionProperty(MagentoRuntime::class, 'runner'))->getValue($runtime));
        self::assertNull((new \ReflectionProperty(MagentoRuntime::class, 'projection'))->getValue($runtime));
    }

    public function testWithADatabaseTheResumeDispatcherIsTheTableQueueOne(): void
    {
        $factory = $this->factory(self::DATABASE);

        self::assertInstanceOf(TableQueueWorkflowResumeDispatcher::class, $factory->resumeDispatcher());
        self::assertSame($factory->database()->resumes, $factory->resumeDispatcher());
    }

    public function testADatabaseAndATemporalDsnTogetherFailWithTheTwoBackendsNamed(): void
    {
        $factory = $this->factory(self::DATABASE + ['durable/temporal/dsn' => self::DSN]);

        foreach ([static fn(RuntimeFactory $f): mixed => $f->create(), static fn(RuntimeFactory $f): mixed => $f->catalog(), static fn(RuntimeFactory $f): mixed => $f->resumeDispatcher()] as $assemble) {
            try {
                $assemble($factory);
                self::fail('Two declared backends must fail assembly.');
            } catch (BackendSelectionException $e) {
                self::assertSame(['database', 'temporal'], [$e->first, $e->second]);
                self::assertInstanceOf(\RuntimeException::class, $e);
            }
        }
    }

    public function testWithNeitherTheMemoryBackendIsUnchanged(): void
    {
        $factory = $this->factory([]);

        $runtime = $factory->create();

        self::assertInstanceOf(InMemoryWorkflowRunCatalog::class, $factory->catalog());
        self::assertInstanceOf(InMemoryEventStore::class, $runtime->eventStore());
        self::assertInstanceOf(InProcessWorkflowResumeDispatcher::class, $factory->resumeDispatcher());
        self::assertNotNull((new \ReflectionProperty(MagentoRuntime::class, 'runner'))->getValue($runtime));
    }

    /** @param array<string, mixed> $env */
    private function factory(array $env): RuntimeFactory
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(static fn($key = null, $default = null): mixed => $env[$key] ?? $default);

        $connections = $this->createStub(ResourceConnection::class);
        $connections->method('getConnectionByName')->willReturn($this->createStub(AdapterInterface::class));

        return new RuntimeFactory(
            deploymentConfig: $config,
            journalConnection: new JournalConnectionResolver($connections, $config, new NullLogger()),
        );
    }
}
