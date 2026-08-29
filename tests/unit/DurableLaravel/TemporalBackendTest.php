<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Le backend Temporal, servi plutôt que refusé.
 *
 * Ce que ces tests ne font pas : parler à un cluster. Ils vérifient que le paquet **assemble** le
 * pont — la suite d'intégration, elle, tourne contre un vrai serveur.
 */
final class TemporalBackendTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=durable-test'
        . '&journal_task_queue=durable-journal&activity_task_queue=durable-activities';

    public function testTemporalIsOneOfTheBackendsThePackageServes(): void
    {
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);

        (new DurableServiceProvider($app))->register();

        // Le journal et le catalogue viennent du cluster…
        self::assertInstanceOf(TemporalWorkflowRunCatalog::class, $app->make(WorkflowRunCatalogInterface::class));
        // …et le worker de tâches est assemblé, prêt à être drainé par la commande.
        self::assertInstanceOf(WorkflowTaskProcessor::class, $app->make(WorkflowTaskProcessor::class));
    }

    public function testMetadataAndParentLinksStayInMemoryBecauseTheClusterHoldsTheState(): void
    {
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);

        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(InMemoryWorkflowMetadataStore::class, $app->make(WorkflowMetadataStore::class));
    }

    public function testTheDsnIsRequiredAndSaysWhat(): void
    {
        $app = $this->container(['backend' => 'temporal']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('durable.temporal.dsn');
        $this->expectExceptionMessage('two task queues');

        (new DurableServiceProvider($app))->register();
    }

    public function testTheDsnIsParsedIntoAConnection(): void
    {
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);
        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(TemporalConnection::class, $app->make(TemporalConnection::class));
    }

    /** @param array<string, mixed> $durable */
    private function container(array $durable): Container
    {
        $app = new Container();
        $app->instance('config', new \ArrayObject(['durable' => $durable], \ArrayObject::ARRAY_AS_PROPS));

        return $app;
    }
}
