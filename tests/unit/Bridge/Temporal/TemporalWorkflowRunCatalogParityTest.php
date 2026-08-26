<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Plugin\Dashboard\TemporalEventsDashboardDataProvider;
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
 * Le déplacement du code de lecture du tableau de bord derrière le port ne doit rien changer à ce
 * qu'un exploitant voit — sauf là où le port sait dire mieux.
 *
 * Ce test compare les deux lectures sur la **même** réponse serveur : le fournisseur actuel, qui
 * vit dans le plugin et parle gRPC, et l'adaptateur qui implémente le port. Il épingle ce qui doit
 * être identique — quelles exécutions, leurs identifiants, leurs noms, leur ordre — et ce qui doit
 * **diverger** : le fournisseur range tout ce qui n'est ni en cours ni terminé sous « failed », si
 * bien qu'une exécution annulée ou passée en continue-as-new s'affiche aujourd'hui en échec. Le
 * port a le vocabulaire pour ne pas mentir, et ce serait un contresens de reproduire ce
 * raccourci-là au nom de la parité.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/tasks.md §2.9
 */
#[RequiresPhpExtension('grpc')]
final class TemporalWorkflowRunCatalogParityTest extends TestCase
{
    public function testBothReadingsSeeTheSameRunsInTheSameOrder(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
            $this->info('wf-2', 'run-2', 'App\\ReportWorkflow', 'reports', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_COMPLETED, 1_700_000_100),
        );

        $legacy = $this->legacyProvider($response)->provideRunsPage();
        $page = $this->catalog($response)->listRuns();

        self::assertSame(
            array_map(static fn(array $run): string => $run['runId'], $legacy['runs']),
            array_map(static fn($run): string => $run->runId, $page->runs),
        );
        self::assertSame(
            array_map(static fn(array $run): string => $run['workflowName'], $legacy['runs']),
            array_map(static fn($run): string => $run->workflowName, $page->runs),
        );
    }

    public function testTheWorkflowIdSurvivesAsTheGroupingIdentifier(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
        );

        $legacy = $this->legacyProvider($response)->provideRunsPage();
        $page = $this->catalog($response)->listRuns();

        self::assertSame('wf-1', $legacy['runs'][0]['workflowId']);
        self::assertSame('wf-1', $page->runs[0]->groupId);
    }

    /**
     * La divergence assumée : ce que le port sait dire et que le fournisseur ne savait pas.
     */
    public function testACancelledRunIsNoLongerReportedAsFailed(): void
    {
        $response = $this->responseWith(
            $this->info('wf-3', 'run-3', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CANCELED, 1_700_000_300),
        );

        $legacy = $this->legacyProvider($response)->provideRunsPage();
        $page = $this->catalog($response)->listRuns();

        self::assertSame('failed', $legacy['runs'][0]['status'], 'le fournisseur actuel range toute fin anormale sous « failed »');
        self::assertSame(WorkflowRunStatus::Cancelled, $page->runs[0]->status);
    }

    public function testAContinuedAsNewRunIsNoLongerReportedAsFailed(): void
    {
        $response = $this->responseWith(
            $this->info('wf-4', 'run-4', 'App\\ReportWorkflow', 'reports', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CONTINUED_AS_NEW, 1_700_000_400),
        );

        self::assertSame('failed', $this->legacyProvider($response)->provideRunsPage()['runs'][0]['status']);
        self::assertSame(WorkflowRunStatus::ContinuedAsNew, $this->catalog($response)->listRuns()->runs[0]->status);
    }

    public function testARealFailureStaysAFailureOnBothSides(): void
    {
        $response = $this->responseWith(
            $this->info('wf-5', 'run-5', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_FAILED, 1_700_000_500),
        );

        self::assertSame('failed', $this->legacyProvider($response)->provideRunsPage()['runs'][0]['status']);
        self::assertSame(WorkflowRunStatus::Failed, $this->catalog($response)->listRuns()->runs[0]->status);
    }

    public function testTheServerPageTokenBecomesTheCursorOnBothSides(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
        );
        $response->setNextPageToken('jeton-serveur');

        self::assertNotNull($this->legacyProvider($response)->provideRunsPage()['nextCursor']);
        self::assertNotNull($this->catalog($response)->listRuns()->nextCursor);
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

    private function legacyProvider(ListWorkflowExecutionsResponse $response): TemporalEventsDashboardDataProvider
    {
        return new TemporalEventsDashboardDataProvider($this->client($response), $this->connection());
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
