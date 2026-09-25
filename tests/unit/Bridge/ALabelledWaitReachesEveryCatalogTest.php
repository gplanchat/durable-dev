<?php

declare(strict_types=1);

namespace unit\Bridge;

use Gplanchat\Bridge\Dbal\Schema\DurableSchema as DbalSchema;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use Gplanchat\Bridge\Illuminate\Schema\DurableSchema as IlluminateSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AsWorkflow(name: 'test.labelled-approval')]
final class LabelledApprovalWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        $env->await(static fn(): bool => false, label: 'signal approve');

        return 'approved';
    }
}

#[AsWorkflow(name: 'test.unlabelled-approval')]
final class UnlabelledApprovalWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        $env->await(static fn(): bool => false);

        return 'approved';
    }
}

/**
 * A labelled condition reaches the run list through the real resume handler, on every catalogue
 * that keeps waits; an unlabelled one still says where it is written (#324).
 *
 * @internal
 */
final class ALabelledWaitReachesEveryCatalogTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(InMemoryEventStore): array{WorkflowRunProjectionInterface&WorkflowRunPickupProjectionInterface, WorkflowRunCatalogInterface}}>
     */
    public static function catalogs(): iterable
    {
        yield 'in memory' => [static function (InMemoryEventStore $journal): array {
            $catalog = new InMemoryWorkflowRunCatalog($journal);

            return [$catalog, $catalog];
        }];
        yield 'DBAL' => [static function (): array {
            $connection = SqlTestDatabase::dbal();
            $schema = new DbalSchema($connection);

            return [new DbalWorkflowRunProjection($connection, $schema), new DbalWorkflowRunCatalog($connection, $schema)];
        }];
        yield 'Illuminate' => [static function (): array {
            $connection = SqlTestDatabase::illuminate();
            $catalog = new IlluminateWorkflowRunCatalog($connection, new IlluminateSchema($connection));

            return [$catalog, $catalog];
        }];
    }

    #[DataProvider('catalogs')]
    public function testTheRunListShowsTheLabel(\Closure $catalogs): void
    {
        self::assertSame('signal approve', $this->waitingOn(LabelledApprovalWorkflow::class, $catalogs));
    }

    #[DataProvider('catalogs')]
    public function testAnUnlabelledWaitStillSaysWhereItIsWritten(\Closure $catalogs): void
    {
        self::assertStringStartsWith('condition at ' . __FILE__ . ':', (string) $this->waitingOn(UnlabelledApprovalWorkflow::class, $catalogs));
    }

    /**
     * @param class-string $workflowClass
     */
    private function waitingOn(string $workflowClass, \Closure $catalogs): ?string
    {
        $journal = new InMemoryEventStore();
        [$projection, $catalog] = $catalogs($journal);
        $store = new ProjectingEventStore($journal, $projection);
        $metadata = new ProjectingWorkflowMetadataStore(new InMemoryWorkflowMetadataStore(), $projection);
        $registry = new WorkflowRegistry();
        $registry->registerClass($workflowClass);
        $metadata->save('exec', $workflowClass, []);

        (new ResumeWorkflowHandler(
            new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            new NullWorkflowResumeDispatcher(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(string $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
            $projection,
        ))(new ResumeWorkflowMessage('exec'));

        return $catalog->listRuns()->runs[0]->waitingOn;
    }
}
