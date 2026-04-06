<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
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
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\SignalWithStartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\SignalWithStartWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * EventStore backed by a Temporal workflow journal (signals + historique serveur).
 *
 * La lecture repose sur {@see GetWorkflowExecutionHistory} (cohérent avec DBAL : pas de blocage
 * sur le worker ni sur une query traitée par poll).
 */
final class TemporalJournalEventStore implements EventStoreInterface
{
    private const GRPC_NOT_FOUND = 5;

    /** @var array<string, string> executionId → run_id (rempli à l’append ; sinon résolu via Describe) */
    private array $runIdByExecutionId = [];

    private readonly HistoryPageMerger $historyMerger;

    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly TemporalConnection $settings,
    ) {
        $this->historyMerger = new HistoryPageMerger($client, $settings->namespace);
    }

    public function append(Event $event): void
    {
        $row = EventDataMapper::fromDomainEvent($event);
        $payload = JsonPlainPayload::encode($row);
        $payloads = JsonPlainPayload::singlePayloads($payload);

        $wfId = $this->settings->journalWorkflowId($event->executionId());

        $req = new SignalWithStartWorkflowExecutionRequest();
        $req->setNamespace($this->settings->namespace);
        $req->setWorkflowId($wfId);
        $req->setWorkflowType(new WorkflowType(['name' => $this->settings->workflowType]));
        $req->setTaskQueue(new TaskQueue(['name' => $this->settings->journalTaskQueue]));
        $req->setSignalName($this->settings->signalAppend);
        $req->setSignalInput($payloads);
        $req->setIdentity($this->settings->identity);
        $req->setWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::WORKFLOW_ID_CONFLICT_POLICY_USE_EXISTING);

        $call = $this->client->SignalWithStartWorkflowExecution($req);
        $started = GrpcUnary::wait($call);
        if (!$started instanceof SignalWithStartWorkflowExecutionResponse) {
            throw new \RuntimeException('Unexpected SignalWithStartWorkflowExecution response type.');
        }

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
        $req->setNamespace($this->settings->namespace);
        $exec = new WorkflowExecution();
        $exec->setWorkflowId($workflowId);
        $exec->setRunId('');
        $req->setExecution($exec);

        $call = $this->client->DescribeWorkflowExecution($req);
        /** @var array{0: object|null, 1: \stdClass} $pair */
        $pair = $call->wait();
        [$response, $status] = $pair;
        $code = $status->code ?? -1;
        if (self::GRPC_NOT_FOUND === $code) {
            return '';
        }
        if (0 !== $code) {
            throw new \RuntimeException(\sprintf('Temporal gRPC error [%s]: %s', (string) $code, (string) ($status->details ?? '')));
        }
        if (!$response instanceof DescribeWorkflowExecutionResponse) {
            return '';
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
