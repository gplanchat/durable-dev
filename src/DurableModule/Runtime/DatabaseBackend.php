<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use Gplanchat\DurableModule\Runtime\ResumeLock\GetLockResumeLock;
use Gplanchat\DurableModule\Runtime\ResumeLock\ResumeLock;
use Gplanchat\DurableModule\Runtime\TableQueue\TableQueue;
use Gplanchat\DurableModule\Runtime\TableQueue\TableQueueActivityTransport;
use Gplanchat\DurableModule\Runtime\TableQueue\TableQueueWorkflowResumeDispatcher;
use Gplanchat\DurableModule\Runtime\TableQueue\TableQueueWorkflowTimerDispatcher;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Store\MagentoChildWorkflowParentLinkStore;
use Gplanchat\DurableModule\Store\MagentoEventStore;
use Gplanchat\DurableModule\Store\MagentoWorkflowMetadataStore;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunCatalog;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunProjection;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Clock\ClockInterface;

/**
 * What `resource/durable` assembles (DUR056): the Magento-adapter stores, the table queue, the
 * locks and the handlers a worker runs, all on the journal's connection. The way
 * `DurableServiceProvider::bindIlluminate()` and `bindResumePath()` assemble the Illuminate one.
 *
 * It is built with `new` by {@see RuntimeFactory}, so Magento's container never instantiates its
 * classes and none of them needs to drop `final`. A worker (#736) reads the handlers, the queue and
 * the lock from here; nothing in this class takes a message off a queue.
 *
 * No Nexus: the factory builds the registry that refuses it, and the runtime is `distributed`, so a
 * workflow that calls a Nexus operation fails with `NexusUnsupportedByBackendException`.
 */
final class DatabaseBackend
{
    public readonly MagentoEventStore $events;
    public readonly EventStoreInterface $eventStore;
    public readonly WorkflowMetadataStore $metadata;
    public readonly ChildWorkflowParentLinkStoreInterface $parentLinks;
    public readonly MagentoWorkflowRunCatalog $catalog;
    public readonly TableQueue $queue;
    public readonly ResumeLock $lock;
    public readonly MagentoActivityAttemptClaim $attemptClaim;
    public readonly WorkflowResumeDispatcher $resumes;
    public readonly WorkflowTimerDispatcher $timers;
    public readonly ActivityMessageProcessor $activities;
    public readonly ResumeWorkflowHandler $resumeHandler;
    public readonly FireWorkflowTimersHandler $timerHandler;

    public function __construct(
        public readonly AdapterInterface $connection,
        DeploymentConfig $deploymentConfig,
        ClockInterface $clock,
        WorkflowRegistry $workflows,
        RegistryActivityExecutor $executor,
        ActivityHeartbeatSenderInterface $heartbeat,
        int $maxActivityRetries,
    ) {
        $schema = new JournalSchema($connection);
        $projection = new MagentoWorkflowRunProjection($connection, $schema);

        $this->events = new MagentoEventStore($connection);
        $this->eventStore = new ProjectingEventStore($this->events, $projection);
        $this->metadata = new ProjectingWorkflowMetadataStore(new MagentoWorkflowMetadataStore($connection, $schema), $projection);
        $this->parentLinks = new MagentoChildWorkflowParentLinkStore($connection, $schema);
        $this->catalog = new MagentoWorkflowRunCatalog($connection, $schema, new JournalRunHistoryReader($this->events));

        $this->queue = TableQueue::configured($connection, $deploymentConfig);
        $this->lock = new GetLockResumeLock($connection);
        $this->attemptClaim = new MagentoActivityAttemptClaim($this->lock);
        $this->resumes = new TableQueueWorkflowResumeDispatcher($this->queue, $this->metadata);
        $this->timers = new TableQueueWorkflowTimerDispatcher($this->queue);
        $transport = new TableQueueActivityTransport($this->queue);

        // `distributed`: the drain is a worker, not this process.
        $runtime = new ExecutionRuntime($this->eventStore, $transport, $executor, $maxActivityRetries, $clock, true);
        $this->activities = new ActivityMessageProcessor(
            $this->eventStore,
            $transport,
            $executor,
            $this->resumes,
            $heartbeat,
            $maxActivityRetries,
            attemptClaim: $this->attemptClaim,
            clock: $clock,
        );
        $this->resumeHandler = new ResumeWorkflowHandler(
            new ExecutionEngine($this->eventStore, $runtime),
            $workflows,
            $this->metadata,
            $this->resumes,
            $this->eventStore,
            $this->parentLinks,
            $this->timers,
            new WorkflowDefinitionLoader(),
            $projection,
        );
        $this->timerHandler = new FireWorkflowTimersHandler($this->eventStore, $runtime, $this->resumes, $this->timers);
    }
}
