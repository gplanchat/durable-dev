<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Store;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsRequest;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Le catalogue des exécutions vu par Temporal, dans le vocabulaire du composant.
 *
 * Temporal conserve le workflow id à travers les continuations et donne à chaque exécution son
 * propre run id : le run id est l'identité, le workflow id le regroupement. Le backend DBAL n'a pas
 * cette seconde notion et laisse `groupId` absent — c'est un fait qu'un backend a et que l'autre
 * n'a pas, pas une lacune.
 *
 * Le curseur transporte tel quel le jeton de page du serveur, encodé pour survivre à une URL.
 *
 * @see DUR006
 */
final class TemporalWorkflowRunCatalog implements WorkflowRunCatalogInterface
{
    private const BACKEND = 'Temporal';

    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly TemporalConnection $connection,
        private readonly ?TemporalHistoryCursor $historyCursor = null,
    ) {}

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        $request = new ListWorkflowExecutionsRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setPageSize(max(1, $limit));
        if (null !== $status) {
            $request->setQuery(self::visibilityQuery($status));
        }
        if (null !== $cursor && '' !== $cursor) {
            $request->setNextPageToken(self::decodeCursor($cursor));
        }

        $response = GrpcUnary::wait($this->client->ListWorkflowExecutions(
            $request,
            [],
            ['timeout' => TemporalGrpcTimeouts::SHORT_US],
        ));

        if (!$response instanceof ListWorkflowExecutionsResponse) {
            return new WorkflowRunPage([]);
        }

        $runs = [];
        foreach ($response->getExecutions() as $info) {
            $run = self::describe($info);
            if (null !== $run) {
                $runs[] = $run;
            }
        }

        // Le serveur ordonne déjà par date de démarrage décroissante, mais la réponse d'une requête
        // de visibilité personnalisée ne le garantit pas : on retrie, comme le faisait la vue.
        usort(
            $runs,
            static fn(WorkflowRunDescription $left, WorkflowRunDescription $right): int => ($right->startedAt?->getTimestamp() ?? 0) <=> ($left->startedAt?->getTimestamp() ?? 0),
        );

        $token = (string) $response->getNextPageToken();

        return new WorkflowRunPage($runs, '' === $token ? null : base64_encode($token));
    }

    /**
     * Sans curseur câblé ou sans id de regroupement, il n'y a rien à demander au serveur : Temporal
     * exige le workflow id pour retrouver une histoire. Une liste vide dit « je n'ai rien à
     * montrer », ce qui est exact, là où une exception dirait « quelque chose ne va pas ».
     *
     * @return list<WorkflowRunEvent>
     */
    public function readHistory(WorkflowRunDescription $run): array
    {
        $workflowId = $run->groupId ?? '';
        if (null === $this->historyCursor || '' === $workflowId || '' === $run->runId) {
            return [];
        }

        return (new TemporalRunHistoryReader($this->historyCursor))->read($workflowId, $run->runId);
    }

    public function checkHealth(): BackendHealth
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            // Une page d'une ligne : la sonde emprunte le même appel que le tableau de bord, donc
            // elle échoue aussi quand le serveur répond mais que le namespace n'existe pas — ce qui
            // est exactement ce que l'exploitant a besoin de savoir.
            $request = new ListWorkflowExecutionsRequest();
            $request->setNamespace($this->connection->namespace->name());
            $request->setPageSize(1);

            GrpcUnary::wait($this->client->ListWorkflowExecutions(
                $request,
                [],
                ['timeout' => TemporalGrpcTimeouts::SHORT_US],
            ));
        } catch (\Throwable $failure) {
            return new BackendHealth(
                self::BACKEND,
                false,
                \sprintf('Temporal namespace "%s" is unreachable: %s', $this->connection->namespace->name(), $failure->getMessage()),
                $checkedAt,
            );
        }

        return new BackendHealth(
            self::BACKEND,
            true,
            \sprintf('Connected to Temporal namespace "%s".', $this->connection->namespace->name()),
            $checkedAt,
        );
    }

    private static function describe(WorkflowExecutionInfo $info): ?WorkflowRunDescription
    {
        $execution = $info->getExecution();
        if (null === $execution) {
            return null;
        }

        $runId = (string) $execution->getRunId();
        if ('' === $runId) {
            return null;
        }

        $type = $info->getType();
        $workflowId = (string) $execution->getWorkflowId();

        return new WorkflowRunDescription(
            runId: $runId,
            workflowName: null !== $type ? (string) $type->getName() : 'UnknownWorkflow',
            status: self::statusOf($info->getStatus()),
            startedAt: self::toDateTime($info->getStartTime()),
            endedAt: self::toDateTime($info->getCloseTime()),
            groupId: '' === $workflowId ? null : $workflowId,
        );
    }

    /**
     * Le fournisseur que ce catalogue remplace rangeait toute fin anormale sous « échec » : annulée,
     * terminée, expirée et continue-as-new s'affichaient identiquement. Le port a le vocabulaire
     * pour les distinguer, et une exécution annulée n'est pas un incident.
     *
     * `TERMINATED` et `TIMED_OUT` restent des échecs faute de cas dédiés : ce sont bien des fins
     * subies, et en inventer deux de plus n'apporterait rien tant qu'aucune vue ne les sépare.
     */
    private static function statusOf(int $status): WorkflowRunStatus
    {
        return match ($status) {
            WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING => WorkflowRunStatus::Running,
            WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_COMPLETED => WorkflowRunStatus::Completed,
            WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CANCELED => WorkflowRunStatus::Cancelled,
            WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CONTINUED_AS_NEW => WorkflowRunStatus::ContinuedAsNew,
            default => WorkflowRunStatus::Failed,
        };
    }

    private static function visibilityQuery(WorkflowRunStatus $status): string
    {
        $serverStatus = match ($status) {
            WorkflowRunStatus::Running => 'Running',
            WorkflowRunStatus::Completed => 'Completed',
            WorkflowRunStatus::Cancelled => 'Canceled',
            WorkflowRunStatus::ContinuedAsNew => 'ContinuedAsNew',
            WorkflowRunStatus::Failed => 'Failed',
        };

        return \sprintf('ExecutionStatus = "%s"', $serverStatus);
    }

    private static function decodeCursor(string $cursor): string
    {
        $raw = base64_decode($cursor, true);

        return false === $raw ? '' : $raw;
    }

    private static function toDateTime(?Timestamp $timestamp): ?\DateTimeImmutable
    {
        if (null === $timestamp || 0 === $timestamp->getSeconds()) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $timestamp->getSeconds()))->setTimezone(new \DateTimeZone('UTC'));
    }
}
