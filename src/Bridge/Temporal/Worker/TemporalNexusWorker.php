<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusHandlerErrorType;
use Gplanchat\Durable\Nexus\Serving\NexusOperationNotHandledException;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;
use Temporal\Api\Common\V1\Callback;
use Temporal\Api\Common\V1\Callback\Nexus as NexusCallback;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Nexus\V1\Failure as NexusFailure;
use Temporal\Api\Nexus\V1\HandlerError;
use Temporal\Api\Nexus\V1\Response as NexusResponse;
use Temporal\Api\Nexus\V1\StartOperationRequest;
use Temporal\Api\Nexus\V1\StartOperationResponse;
use Temporal\Api\Nexus\V1\StartOperationResponse\Async as StartOperationAsync;
use Temporal\Api\Nexus\V1\StartOperationResponse\Sync as StartOperationSync;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;

/**
 * Poll la file de tâches Nexus, route vers le gestionnaire déclaré, et répond.
 *
 * Ce worker ne partage rien avec {@see WorkflowTaskProcessor} sinon la forme « poll et réponds » :
 * pas d'historique, pas de rejeu, pas de déterminisme, pas de slots. Le faire passer par le worker
 * de workflow traînerait un moteur de rejeu dans un chemin qui ne rejoue jamais.
 *
 * Trois mesures de sonde le façonnent, et aucune n'est un détail :
 *
 * - **§1.2** — une file vide rend un jeton vide et une requête nulle, après ~11 s. C'est un succès,
 *   pas une erreur : la boucle repart.
 * - **§1.7** — deux budgets. `request-timeout` (~9 s) borne la réponse à *cette tâche* ;
 *   `operation-timeout` borne l'opération. Un gestionnaire qui travaille plus de neuf secondes voit
 *   sa tâche redélivrée et son travail recommencer. C'est ce que la forme différée évite.
 * - **§3.1** — ce qui règle une opération différée est le `callback` de la tâche, attaché au
 *   workflow qui la remplit. Retiré, l'appelant reste à `NEXUS_OPERATION_STARTED` pour toujours.
 *   D'où l'ordre ici : on démarre le workflow **avant** de répondre, parce que `completion_callbacks`
 *   ne se pose qu'au démarrage.
 */
final readonly class TemporalNexusWorker
{
    public function __construct(
        private WorkflowServiceNexusRpc $nexusRpc,
        private TemporalConnection $connection,
        private NexusOperationRegistry $registry,
        private string $taskQueue,
    ) {}

    /**
     * Un long-poll ; si une tâche arrive, routage et réponse.
     */
    public function pollOnce(): void
    {
        $request = new PollNexusTaskQueueRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setTaskQueue(new TaskQueue(['name' => $this->taskQueue]));
        $request->setIdentity($this->connection->identity . '-nexus');

        $task = $this->nexusRpc->pollNexusTaskQueue($request);

        $taskToken = (string) $task->getTaskToken();
        if ('' === $taskToken) {
            // §1.2 : rien à faire. Le traiter en erreur ferait boucler sur un cas nominal.
            return;
        }

        $start = $task->getRequest()?->getStartOperation();
        if (null === $start) {
            // Une variante que ce worker ne sert pas encore — l'annulation, §4. La refuser
            // nommément vaut mieux que de laisser la tâche expirer en silence.
            $this->respondFailed($taskToken, NexusHandlerErrorType::NotImplemented, 'This worker only serves start_operation tasks.');

            return;
        }

        $service = (string) $start->getService();
        $operation = (string) $start->getOperation();

        try {
            $response = $this->registry->dispatch(
                NexusService::named($service),
                NexusOperationName::named($operation),
                $this->decodePayload($start),
            );
        } catch (NexusOperationNotHandledException $refusal) {
            // §2.4 : la réponse dit que personne ne sert, et la boucle continue de servir le reste.
            $this->respondFailed($taskToken, $refusal->type(), $refusal->getMessage());

            return;
        } catch (\Throwable $raised) {
            // §1b.3 : une exception ordinaire vaut INTERNAL, donc réessayable — comme dans tous les
            // autres SDK. Un gestionnaire qui veut un refus définitif le dit avec son type.
            $this->respondFailed($taskToken, NexusHandlerErrorType::Internal, $raised->getMessage());

            return;
        }

        if ($response->isImmediate) {
            $this->respondCompletedNow($taskToken, $response->result);

            return;
        }

        $this->respondFulfilledByWorkflow($taskToken, $start, $response);
    }

    private function decodePayload(StartOperationRequest $start): mixed
    {
        $payload = $start->getPayload();

        return null === $payload ? null : JsonPlainPayload::decode($payload);
    }

    private function respondCompletedNow(string $taskToken, mixed $result): void
    {
        $sync = new StartOperationSync();
        $sync->setPayload(JsonPlainPayload::encode($result));

        $start = new StartOperationResponse();
        $start->setSyncSuccess($sync);

        $this->respondCompleted($taskToken, $start);
    }

    private function respondFulfilledByWorkflow(
        string $taskToken,
        StartOperationRequest $task,
        NexusOperationResponse $response,
    ): void {
        $workflowId = $response->workflowId ?? \sprintf('nexus-%s', bin2hex(random_bytes(8)));

        // L'ordre compte : `completion_callbacks` ne se pose qu'au démarrage (§3.1). Répondre
        // d'abord et démarrer ensuite laisserait l'appelant attendre une issue qui n'arriverait
        // jamais, sans que rien ne le signale.
        $nexusCallback = new NexusCallback();
        $nexusCallback->setUrl((string) $task->getCallback());
        // `getCallbackHeader()` rend toujours une MapField, vide au besoin : il n'y a pas de cas
        // « pas d'en-tête » à distinguer, et le recopier tel quel est ce qui préserve ce que le
        // serveur a mis dedans.
        $nexusCallback->setHeader($task->getCallbackHeader());
        $callback = new Callback();
        $callback->setNexus($nexusCallback);

        $start = new StartWorkflowExecutionRequest();
        $start->setNamespace($this->connection->namespace->name());
        $start->setWorkflowId($workflowId);
        $start->setWorkflowType((new WorkflowType())->setName((string) $response->workflowType));
        $start->setTaskQueue(new TaskQueue(['name' => $this->connection->workflowTaskQueue->name()]));
        $start->setIdentity($this->connection->identity . '-nexus');
        $start->setRequestId(bin2hex(random_bytes(8)));
        $start->setInput((new Payloads())->setPayloads([JsonPlainPayload::encode($response->workflowInput)]));
        $start->setCompletionCallbacks([$callback]);

        $this->nexusRpc->startWorkflowExecution($start);

        $async = new StartOperationAsync();
        $async->setOperationToken($workflowId);

        $operation = new StartOperationResponse();
        $operation->setAsyncSuccess($async);

        $this->respondCompleted($taskToken, $operation);
    }

    private function respondCompleted(string $taskToken, StartOperationResponse $start): void
    {
        $response = new NexusResponse();
        $response->setStartOperation($start);

        $request = new RespondNexusTaskCompletedRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setIdentity($this->connection->identity . '-nexus');
        $request->setTaskToken($taskToken);
        $request->setResponse($response);

        $this->nexusRpc->respondNexusTaskCompleted($request);
    }

    private function respondFailed(string $taskToken, NexusHandlerErrorType $type, string $message): void
    {
        $failure = new NexusFailure();
        $failure->setMessage($message);

        $error = new HandlerError();
        $error->setErrorType($type->value);
        $error->setFailure($failure);
        $error->setRetryBehavior($type->isRetryable()
            ? \Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_RETRYABLE
            : \Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_NON_RETRYABLE);

        $request = new RespondNexusTaskFailedRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setIdentity($this->connection->identity . '-nexus');
        $request->setTaskToken($taskToken);
        $request->setError($error);

        $this->nexusRpc->respondNexusTaskFailed($request);
    }
}
