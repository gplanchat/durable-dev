<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RequestCancelWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\RequestCancelWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedResponse;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Les RPC qui concernent les **tâches Nexus** servies par ce composant.
 *
 * `StartWorkflowExecution` figure ici avec les trois autres, et ce n'est pas un fourre-tout : la
 * sonde 3.1 a montré que ce qui règle une opération différée est le `callback` de la tâche attaché
 * au workflow qui la remplit, via `completion_callbacks` — un champ qui ne se pose qu'au démarrage.
 * Démarrer ce workflow fait donc partie du geste de réponse, pas d'un autre.
 *
 * `RequestCancelWorkflowExecution` y figure pour la même raison : la sonde §4 a montré que la
 * tâche d'annulation nomme le jeton rendu au démarrage, et que ce jeton est le workflow qui porte
 * l'opération. Annuler l'opération, c'est annuler ce workflow.
 *
 * @see \Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker
 */
final readonly class WorkflowServiceNexusRpc
{
    public function __construct(
        private WorkflowServiceClient $client,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function pollNexusTaskQueue(
        PollNexusTaskQueueRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): PollNexusTaskQueueResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::LONG_POLL_US], $callOptions);
        $r = GrpcUnary::wait($this->client->PollNexusTaskQueue($request, $metadata, $opts));
        \assert($r instanceof PollNexusTaskQueueResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondNexusTaskCompleted(
        RespondNexusTaskCompletedRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondNexusTaskCompletedResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RespondNexusTaskCompleted($request, $metadata, $opts));
        \assert($r instanceof RespondNexusTaskCompletedResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function respondNexusTaskFailed(
        RespondNexusTaskFailedRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RespondNexusTaskFailedResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RespondNexusTaskFailed($request, $metadata, $opts));
        \assert($r instanceof RespondNexusTaskFailedResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function startWorkflowExecution(
        StartWorkflowExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): StartWorkflowExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->StartWorkflowExecution($request, $metadata, $opts));
        \assert($r instanceof StartWorkflowExecutionResponse);

        return $r;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $callOptions
     */
    public function requestCancelWorkflowExecution(
        RequestCancelWorkflowExecutionRequest $request,
        array $metadata = [],
        array $callOptions = [],
    ): RequestCancelWorkflowExecutionResponse {
        $opts = array_merge(['timeout' => TemporalGrpcTimeouts::SHORT_US], $callOptions);
        $r = GrpcUnary::wait($this->client->RequestCancelWorkflowExecution($request, $metadata, $opts));
        \assert($r instanceof RequestCancelWorkflowExecutionResponse);

        return $r;
    }
}
