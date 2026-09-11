<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Journal\HistoryPageMerger;
use Gplanchat\Bridge\Temporal\Journal\JournalStateResolver;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\EventStoreInterface;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\WorkflowIdConflictPolicy;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\SignalWithStartWorkflowExecutionRequest;

/**
 * EventStore backed by a Temporal workflow journal (signals + server history).
 *
 * Reading relies on {@see GetWorkflowExecutionHistory} (consistent with DBAL: no blocking on the
 * worker nor on a query served by poll).
 */
final class TemporalJournalEventStore implements EventStoreInterface
{
    private const GRPC_NOT_FOUND = 5;

    /** @var array<string, string> executionId → run_id (filled at append; otherwise resolved via Describe) */
    private array $runIdByExecutionId = [];

    private readonly HistoryPageMerger $historyMerger;

    public function __construct(
        private readonly WorkflowServiceClientInterface $client,
        private readonly TemporalConnection $settings,
    ) {
        $this->historyMerger = new HistoryPageMerger($client, $settings->namespace->name());
    }

    public function append(Event $event): void
    {
        $row = EventDataMapper::fromDomainEvent($event);
        $payload = JsonPlainPayload::encode($row);
        $payloads = JsonPlainPayload::singlePayloads($payload);

        $wfId = $this->settings->journalWorkflowId($event->executionId());

        $req = new SignalWithStartWorkflowExecutionRequest();
        $req->setNamespace($this->settings->namespace->name());
        $req->setWorkflowId($wfId);
        $req->setWorkflowType(new WorkflowType(['name' => $this->settings->workflowType]));
        $req->setTaskQueue(new TaskQueue(['name' => $this->settings->journalTaskQueue->name()]));
        $req->setSignalName($this->settings->signalAppend);
        $req->setSignalInput($payloads);
        $req->setIdentity($this->settings->identity);
        $req->setWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::WORKFLOW_ID_CONFLICT_POLICY_USE_EXISTING);

        $started = $this->client->SignalWithStartWorkflowExecution($req);

        $runId = (string) $started->getRunId();
        if ('' !== $runId) {
            $this->runIdByExecutionId[$event->executionId()] = $runId;
        }
    }

    public function readStream(string $executionId): iterable
    {
        foreach ($this->readStreamWithRecordedAt($executionId) as $entry) {
            yield $entry['event'];
        }
    }

    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        $wfId = $this->settings->journalWorkflowId($executionId);

        $runId = $this->runIdByExecutionId[$executionId] ?? '';
        if ('' === $runId) {
            $runId = $this->resolveRunIdViaDescribe($wfId);
            if ('' !== $runId) {
                $this->runIdByExecutionId[$executionId] = $runId;
            }
        }

        if ('' === $runId) {
            return;
        }

        $exec = new WorkflowExecution();
        $exec->setWorkflowId($wfId);
        $exec->setRunId($runId);

        $history = $this->historyMerger->fullHistoryForExecution($exec);
        $rows = JournalStateResolver::journalRowsFromHistory($history, $this->settings->signalAppend);

        foreach ($rows as $row) {
            /* @var array<string, mixed> $row */
            yield [
                'event' => EventDataMapper::toDomainEvent($row),
                'recordedAt' => null,
            ];
        }
    }

    public function countEventsInStream(string $executionId): int
    {
        $n = 0;
        foreach ($this->readStream($executionId) as $_) {
            ++$n;
        }

        return $n;
    }

    private function resolveRunIdViaDescribe(string $workflowId): string
    {
        $req = new DescribeWorkflowExecutionRequest();
        $req->setNamespace($this->settings->namespace->name());
        $exec = new WorkflowExecution();
        $exec->setWorkflowId($workflowId);
        $exec->setRunId('');
        $req->setExecution($exec);

        try {
            $response = $this->client->DescribeWorkflowExecution($req);
        } catch (\RuntimeException $e) {
            if (self::GRPC_NOT_FOUND === $e->getCode()) {
                return '';
            }

            throw $e;
        }
        $info = $response->getWorkflowExecutionInfo();
        if (null === $info) {
            return '';
        }
        $e = $info->getExecution();
        if (null === $e) {
            return '';
        }

        return (string) $e->getRunId();
    }
}
