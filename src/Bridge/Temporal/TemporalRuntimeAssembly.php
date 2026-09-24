<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * The Temporal graph every host needs, written once: one client, one history cursor, and what
 * reads or drives the cluster through them. The Symfony bundle, the Laravel provider and the
 * Magento factory take their services from here instead of writing the graph out three times.
 *
 * Each object is built on first use and kept: a host that asks twice gets the same one, and a
 * Magento request that needs the catalog and the client opens one channel, not two.
 *
 * @see https://github.com/gplanchat/durable-dev/issues/356
 */
final class TemporalRuntimeAssembly
{
    private ?TemporalHistoryCursor $historyCursor = null;
    private ?TemporalWorkflowRunCatalog $runCatalog = null;
    private ?WorkflowTaskProcessor $workflowTaskProcessor = null;
    private ?WorkflowClient $workflowClient = null;
    private ?WorkflowServiceActivityRpc $activityRpc = null;
    private ?WorkflowServiceExecutionRpc $executionRpc = null;
    private ?WorkflowServiceNexusRpc $nexusRpc = null;

    public function __construct(
        private readonly WorkflowServiceClientInterface $client,
        private readonly TemporalConnection $connection,
        private readonly WorkflowRegistry $registry,
        private readonly WorkflowDefinitionLoader $definitionLoader,
    ) {}

    public function historyCursor(): TemporalHistoryCursor
    {
        return $this->historyCursor ??= new TemporalHistoryCursor($this->client, $this->connection);
    }

    public function runCatalog(): TemporalWorkflowRunCatalog
    {
        return $this->runCatalog ??= new TemporalWorkflowRunCatalog($this->client, $this->connection, $this->historyCursor());
    }

    public function workflowTaskProcessor(): WorkflowTaskProcessor
    {
        return $this->workflowTaskProcessor ??= new WorkflowTaskProcessor(
            $this->client,
            $this->connection,
            new WorkflowTaskRunner($this->historyCursor(), $this->registry, $this->connection, $this->definitionLoader),
        );
    }

    public function workflowClient(): WorkflowClient
    {
        return $this->workflowClient ??= new WorkflowClient(
            $this->client,
            $this->connection,
            $this->historyCursor(),
            $this->executionRpc(),
            $this->definitionLoader,
        );
    }

    /** The journal read through to the cluster, over the host's store for the current turn. */
    public function readThroughEventStore(EventStoreInterface $local): TemporalReadThroughEventStore
    {
        return new TemporalReadThroughEventStore($local, $this->historyCursor(), $this->workflowClient());
    }

    public function activityRpc(): WorkflowServiceActivityRpc
    {
        return $this->activityRpc ??= new WorkflowServiceActivityRpc($this->client);
    }

    public function executionRpc(): WorkflowServiceExecutionRpc
    {
        return $this->executionRpc ??= new WorkflowServiceExecutionRpc($this->client);
    }

    public function nexusRpc(): WorkflowServiceNexusRpc
    {
        return $this->nexusRpc ??= new WorkflowServiceNexusRpc($this->client);
    }
}
