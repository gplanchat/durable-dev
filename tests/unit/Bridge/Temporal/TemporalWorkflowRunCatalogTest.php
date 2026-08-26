<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Grpc\UnaryCall;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Ce que le catalogue Temporal dit d'une réponse de visibilité.
 *
 * Ce fichier était un test de **parité** : il lisait la même réponse serveur avec le fournisseur du
 * plugin et avec ce catalogue, pour prouver que déplacer le code derrière le port ne changeait rien
 * — sauf là où le port sait dire mieux. Le fournisseur ayant rejoint le pont puis disparu, la
 * comparaison n'a plus de second terme, et il ne reste que le contrat de l'adaptateur.
 *
 * Ce qui reste vaut d'être rappelé : le fournisseur rangeait **tout** ce qui n'était ni en cours ni
 * terminé sous « failed ». Une exécution annulée ou passée en continue-as-new s'affichait donc en
 * échec, et un workflow long virait au rouge à chaque roulement. Les deux tests qui portent encore
 * `IsNoLongerReportedAsFailed` gardent cette correction.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/tasks.md §2.9 §5.1
 */
#[RequiresPhpExtension('grpc')]
final class TemporalWorkflowRunCatalogTest extends TestCase
{
    public function testRunsComeBackNamedAndInStartOrder(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
            $this->info('wf-2', 'run-2', 'App\\ReportWorkflow', 'reports', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_COMPLETED, 1_700_000_100),
        );

        $page = $this->catalog($response)->listRuns();

        self::assertSame(['run-1', 'run-2'], array_map(static fn($run): string => $run->runId, $page->runs));
        self::assertSame(
            ['App\\OrderWorkflow', 'App\\ReportWorkflow'],
            array_map(static fn($run): string => $run->workflowName, $page->runs),
        );
    }

    public function testTheWorkflowIdSurvivesAsTheGroupingIdentifier(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
        );

        self::assertSame('wf-1', $this->catalog($response)->listRuns()->runs[0]->groupId);
    }

    /**
     * La divergence assumée : ce que le port sait dire et que le fournisseur ne savait pas.
     */
    public function testACancelledRunIsNoLongerReportedAsFailed(): void
    {
        $response = $this->responseWith(
            $this->info('wf-3', 'run-3', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CANCELED, 1_700_000_300),
        );

        self::assertSame(WorkflowRunStatus::Cancelled, $this->catalog($response)->listRuns()->runs[0]->status);
    }

    public function testAContinuedAsNewRunIsNoLongerReportedAsFailed(): void
    {
        $response = $this->responseWith(
            $this->info('wf-4', 'run-4', 'App\\ReportWorkflow', 'reports', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CONTINUED_AS_NEW, 1_700_000_400),
        );

        self::assertSame(WorkflowRunStatus::ContinuedAsNew, $this->catalog($response)->listRuns()->runs[0]->status);
    }

    public function testARealFailureIsAFailure(): void
    {
        $response = $this->responseWith(
            $this->info('wf-5', 'run-5', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_FAILED, 1_700_000_500),
        );

        self::assertSame(WorkflowRunStatus::Failed, $this->catalog($response)->listRuns()->runs[0]->status);
    }

    public function testTheServerPageTokenBecomesTheCursor(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
        );
        $response->setNextPageToken('jeton-serveur');

        self::assertSame(base64_encode('jeton-serveur'), $this->catalog($response)->listRuns()->nextCursor);
    }

    private function info(string $workflowId, string $runId, string $type, string $taskQueue, int $status, int $startedAt): WorkflowExecutionInfo
    {
        $execution = new WorkflowExecution();
        $execution->setWorkflowId($workflowId);
        $execution->setRunId($runId);

        $workflowType = new WorkflowType();
        $workflowType->setName($type);

        $start = new Timestamp();
        $start->setSeconds($startedAt);

        $info = new WorkflowExecutionInfo();
        $info->setExecution($execution);
        $info->setType($workflowType);
        $info->setTaskQueue($taskQueue);
        $info->setStatus($status);
        $info->setStartTime($start);

        return $info;
    }

    private function responseWith(WorkflowExecutionInfo ...$infos): ListWorkflowExecutionsResponse
    {
        $response = new ListWorkflowExecutionsResponse();
        $response->setExecutions($infos);

        return $response;
    }

    private function catalog(ListWorkflowExecutionsResponse $response): TemporalWorkflowRunCatalog
    {
        return new TemporalWorkflowRunCatalog($this->client($response), $this->connection());
    }

    private function connection(): TemporalConnection
    {
        return new TemporalConnection('localhost:7233', 'durable-test');
    }

    private function client(ListWorkflowExecutionsResponse $response): WorkflowServiceClient
    {
        $status = new \stdClass();
        $status->code = \Grpc\STATUS_OK;
        $status->details = '';

        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn([$response, $status]);

        $client = $this->createMock(WorkflowServiceClient::class);
        $client->method('ListWorkflowExecutions')->willReturn($call);

        return $client;
    }
}
