<?php

declare(strict_types=1);

namespace Gplanchat\DurableSqlSpike\Runtime;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Gplanchat\Bridge\Dbal\Messenger\LockActivityAttemptClaim;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use Gplanchat\DurableSqlSpike\Workflow\LoggingSpikeActivities;
use Gplanchat\DurableSqlSpike\Workflow\SpikeNexusWorkflow;
use Gplanchat\DurableSqlSpike\Workflow\SpikeOrderWorkflow;
use Magento\Framework\App\DeploymentConfig;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;

/**
 * Option A, assembled the way DurableServiceProvider::bindIlluminate() + bindResumePath() do it.
 *
 * The journal's connection comes from env.php `durable/db/url`, never from ResourceConnection:
 * Magento's own connection does not know it exists, so no Magento transaction can straddle it.
 */
class SqlRuntime
{
    public const BACKEND = 'magento-dbal';
    public const LOCK_TTL = 30.0;

    public readonly Connection $connection;
    public readonly DurableSchema $schema;
    public readonly ProjectingEventStore $events;
    public readonly ProjectingWorkflowMetadataStore $metadata;
    public readonly TableQueue $queue;
    public readonly ResumeWorkflowHandler $resume;
    public readonly FireWorkflowTimersHandler $timers;
    public readonly ActivityMessageProcessor $activities;
    public readonly LockFactory $locks;
    public readonly NexusOperationRegistry $nexus;

    public function __construct(DeploymentConfig $config)
    {
        $url = $config->get('durable/db/url') ?? throw new \RuntimeException('Set durable/db/url in app/etc/env.php.');
        $this->connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse($url));
        $this->schema = new DurableSchema($this->connection);
        $projection = new DbalWorkflowRunProjection($this->connection, $this->schema);
        $this->events = new ProjectingEventStore(new DbalEventStore($this->connection, $this->schema), $projection);
        $this->metadata = new ProjectingWorkflowMetadataStore(new DbalWorkflowMetadataStore($this->connection, $this->schema), $projection);
        $this->queue = new TableQueue($this->connection, $this->metadata);
        // The lock lives on the journal's connection too: a TTL row, so a killed worker's lock outlives it by up to LOCK_TTL.
        $this->locks = new LockFactory(new DoctrineDbalStore($this->connection));

        $workflows = new WorkflowRegistry();
        $workflows->registerClass(SpikeOrderWorkflow::class);
        $workflows->registerClass(SpikeNexusWorkflow::class);
        $executor = new RegistryActivityExecutor();
        $handler = new LoggingSpikeActivities();
        foreach ((new ActivityContractResolver())->resolveActivityMethods(\Gplanchat\DurableSqlSpike\Workflow\SpikeActivities::class) as $method => $name) {
            $executor->register($name, new PayloadToContractMethodInvoker($handler, \Gplanchat\DurableSqlSpike\Workflow\SpikeActivities::class, $method));
        }

        $runtime = new ExecutionRuntime($this->events, $this->queue, $executor, 0, null, true);
        $this->resume = new ResumeWorkflowHandler(
            new ExecutionEngine($this->events, $runtime),
            $workflows,
            $this->metadata,
            $this->queue,
            $this->events,
            new DbalChildWorkflowParentLinkStore($this->connection, $this->schema),
            $this->queue,
            new WorkflowDefinitionLoader(),
            $projection,
        );
        $this->timers = new FireWorkflowTimersHandler($this->events, $runtime, $this->queue, $this->queue);
        $this->activities = new ActivityMessageProcessor(
            $this->events, $this->queue, $executor, $this->queue, new NullActivityHeartbeatSender(), 0,
            attemptClaim: new LockActivityAttemptClaim($this->locks, self::LOCK_TTL),
        );
        $this->nexus = NexusOperationRegistry::unavailableOn(self::BACKEND);
    }

    /** What `durable:setup` would do on this connection: Magento's setup:upgrade never sees it. */
    public function setup(): void
    {
        $this->schema->setup();
        $this->queue->setup();
        (new DoctrineDbalStore($this->connection))->createTable();
    }
}
