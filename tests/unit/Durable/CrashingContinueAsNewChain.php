<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;

require_once __DIR__ . '/AnAsyncChildThatContinuesAsNewReportsToItsParentTest.php';

/**
 * child-1, linked to parent-1, continues twice. The chain is also every store the handler writes
 * to, and each write can stop the process once, at the point the test names.
 *
 * Its dispatcher saves the run's metadata, as the real ones do. Deferred, it holds what it sends
 * until the handler returns and drops it when the handler throws, as Messenger's
 * `DispatchAfterCurrentBusStamp` does. Otherwise it sends at once, as the Laravel queue does.
 */
final class CrashingContinueAsNewChain implements WorkflowMetadataStore, ChildWorkflowParentLinkStoreInterface, EventStoreInterface, WorkflowResumeDispatcher
{
    /** @var list<string> */
    public array $startedRuns = [];
    public readonly InMemoryChildWorkflowParentLinkStore $links;
    private readonly InMemoryEventStore $journal;
    private readonly InMemoryWorkflowMetadataStore $metadata;
    private readonly ResumeWorkflowHandler $handler;
    private bool $crashed = false;

    /** @var list<string> */
    private array $held = [];

    public function __construct(private readonly string $point, private readonly bool $deferred = true)
    {
        $this->journal = new InMemoryEventStore();
        $this->links = new InMemoryChildWorkflowParentLinkStore();
        $this->metadata = new InMemoryWorkflowMetadataStore();
        $this->metadata->save(ExecutionId::fromString('child-1'), ContinuingChildWorkflow::class, ['n' => 0, 'failAtTheEnd' => false]);
        $this->links->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-1'));
        $registry = new WorkflowRegistry();
        $registry->registerClass(ContinuingChildWorkflow::class);
        $this->handler = new ResumeWorkflowHandler(
            new ExecutionEngine($this->journal, new ExecutionRuntime($this->journal, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $this,
            $this,
            $this,
            $this,
            new RecordingTimerDispatcher(),
            new WorkflowDefinitionLoader(),
        );
    }

    public function resume(string $executionId): void
    {
        try {
            ($this->handler)(new ResumeWorkflowMessage($executionId));
        } catch (\Throwable $e) {
            $this->held = [];

            throw $e;
        }
        array_push($this->startedRuns, ...$this->held);
        $this->held = [];
    }

    /** Every dispatch is delivered, the duplicates too. */
    public function driveStartedRuns(): void
    {
        for ($i = 0; $i < \count($this->startedRuns); ++$i) {
            $this->resume($this->startedRuns[$i]);
        }
    }

    /**
     * @template T of Event
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function eventsOf(string $executionId, string $class): array
    {
        return array_values(array_filter(
            iterator_to_array($this->journal->readStream(ExecutionId::fromString($executionId)), false),
            static fn(object $e): bool => $e instanceof $class,
        ));
    }

    private function crashAt(string $point): void
    {
        if (!$this->crashed && $point === $this->point) {
            $this->crashed = true;

            throw new \LogicException($point);
        }
    }

    public function save(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->metadata->save($executionId, $workflowType, $payload);
        $this->crashAt('save');
    }

    public function insertIfAbsent(ExecutionId $executionId, string $workflowType, array $payload): bool
    {
        $inserted = $this->metadata->insertIfAbsent($executionId, $workflowType, $payload);
        $this->crashAt('save');

        return $inserted;
    }

    public function markCompleted(ExecutionId $executionId): void
    {
        $this->crashAt('before markCompleted');
        $this->metadata->markCompleted($executionId);
        $this->crashAt('after markCompleted');
    }

    public function get(ExecutionId $executionId): ?array
    {
        return $this->metadata->get($executionId);
    }

    public function hasActiveWorkflowMetadata(ExecutionId $executionId): bool
    {
        return $this->metadata->hasActiveWorkflowMetadata($executionId);
    }

    public function delete(ExecutionId $executionId): void
    {
        $this->metadata->delete($executionId);
    }

    public function link(ExecutionId $childExecutionId, ExecutionId $parentExecutionId): void
    {
        $this->links->link($childExecutionId, $parentExecutionId);
        $this->crashAt('link');
    }

    public function getParentExecutionId(ExecutionId $childExecutionId): ?ExecutionId
    {
        return $this->links->getParentExecutionId($childExecutionId);
    }

    public function getChildExecutionIdsForParent(ExecutionId $parentExecutionId): array
    {
        return $this->links->getChildExecutionIdsForParent($parentExecutionId);
    }

    public function unlink(ExecutionId $childExecutionId): void
    {
        $this->links->unlink($childExecutionId);
        $this->crashAt('unlink');
    }

    public function append(Event $event): void
    {
        if ($event instanceof ExecutionStarted) {
            $this->crashAt('append');
        }
        $this->journal->append($event);
    }

    public function readStream(ExecutionId $executionId): iterable
    {
        return $this->journal->readStream($executionId);
    }

    public function readStreamWithRecordedAt(ExecutionId $executionId): iterable
    {
        return $this->journal->readStreamWithRecordedAt($executionId);
    }

    public function countEventsInStream(ExecutionId $executionId): int
    {
        return $this->journal->countEventsInStream($executionId);
    }

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void {}

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->crashAt('dispatch');
        $this->metadata->save($executionId, $workflowType, $payload);
        if ($this->deferred) {
            $this->held[] = $executionId->toString();
        } else {
            $this->startedRuns[] = $executionId->toString();
        }
    }
}
