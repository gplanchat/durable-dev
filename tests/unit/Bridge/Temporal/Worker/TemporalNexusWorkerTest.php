<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\Serving\NexusHandlerErrorType;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;
use Grpc\UnaryCall;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Nexus\V1\Request as NexusRequest;
use Temporal\Api\Nexus\V1\StartOperationRequest;
use Temporal\Api\Workflowservice\V1\PollNexusTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondNexusTaskFailedResponse;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Le worker vu depuis le fil : ce qu'il envoie au serveur pour chaque forme de réponse.
 *
 * Le client gRPC est simulé, donc rien ici ne dépend d'un serveur — c'est le niveau où les quatre
 * branches (vide, immédiate, différée, refusée) se lisent d'un coup d'œil. Ce que ces tests ne
 * peuvent pas prouver, c'est que le serveur accepte ce qu'on lui envoie : c'est le rôle de
 * {@see \integration\Temporal\NexusServedOperationTest}.
 */
#[RequiresPhpExtension('grpc')]
final class TemporalNexusWorkerTest extends TestCase
{
    private WorkflowServiceClient $grpc;

    protected function setUp(): void
    {
        $this->grpc = $this->createMock(WorkflowServiceClient::class);
    }

    public function testAnEmptyPollDoesNothingAtAll(): void
    {
        // §1.2 : une file vide rend un jeton vide après ~11 s, et c'est un succès. Le traiter
        // comme une erreur ferait tourner la boucle à vide en criant.
        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call(new PollNexusTaskQueueResponse()));
        $this->grpc->expects($this->never())->method('RespondNexusTaskCompleted');
        $this->grpc->expects($this->never())->method('RespondNexusTaskFailed');
        $this->grpc->expects($this->never())->method('StartWorkflowExecution');

        $this->worker(new NexusOperationRegistry())->pollOnce();
    }

    public function testAnImmediateAnswerIsSentAsASyncSuccess(): void
    {
        $registry = new NexusOperationRegistry();
        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static fn(mixed $payload): NexusOperationResponse => NexusOperationResponse::completed(['charged' => $payload['amount']]),
        );

        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->startTask(['amount' => 10])));
        $this->grpc->expects($this->never())->method('StartWorkflowExecution');

        $sent = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskCompleted')
            ->willReturnCallback(function (RespondNexusTaskCompletedRequest $request) use (&$sent): UnaryCall {
                $sent = $request;

                return $this->call(new RespondNexusTaskCompletedResponse());
            });

        $this->worker($registry)->pollOnce();

        self::assertSame('jeton-de-tache', $sent?->getTaskToken());
        $sync = $sent?->getResponse()?->getStartOperation()?->getSyncSuccess();
        self::assertNotNull($sync, 'Une réponse immédiate doit partir en syncSuccess.');
        self::assertSame(['charged' => 10], JsonPlainPayload::decode($sync->getPayload()));
    }

    public function testADeferredAnswerStartsTheWorkflowCarryingTheTasksCallbackBeforeAnswering(): void
    {
        // §3.1, mesuré : c'est le callback attaché au workflow qui règle l'opération, et
        // `completion_callbacks` ne se pose qu'au démarrage. Répondre d'abord laisserait
        // l'appelant attendre une issue qui n'arriverait jamais.
        $registry = new NexusOperationRegistry();
        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static fn(): NexusOperationResponse => NexusOperationResponse::fulfilledByWorkflow('ChargeWorkflow', ['amount' => 10], 'charge-1'),
        );

        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->startTask(['amount' => 10])));

        $order = [];
        $started = null;
        $this->grpc->expects($this->once())->method('StartWorkflowExecution')
            ->willReturnCallback(function (StartWorkflowExecutionRequest $request) use (&$order, &$started): UnaryCall {
                $order[] = 'start';
                $started = $request;

                return $this->call(new StartWorkflowExecutionResponse());
            });

        $answered = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskCompleted')
            ->willReturnCallback(function (RespondNexusTaskCompletedRequest $request) use (&$order, &$answered): UnaryCall {
                $order[] = 'respond';
                $answered = $request;

                return $this->call(new RespondNexusTaskCompletedResponse());
            });

        $this->worker($registry)->pollOnce();

        self::assertSame(['start', 'respond'], $order, 'Le workflow doit démarrer avant la réponse.');

        self::assertSame('charge-1', $started?->getWorkflowId());
        self::assertSame('ChargeWorkflow', $started?->getWorkflowType()?->getName());
        $callbacks = $started?->getCompletionCallbacks();
        self::assertNotNull($callbacks);
        self::assertCount(1, $callbacks, "Sans callback attaché, l'appelant n'apprend jamais l'issue.");
        self::assertSame('temporal://system', $callbacks[0]->getNexus()?->getUrl());

        $async = $answered?->getResponse()?->getStartOperation()?->getAsyncSuccess();
        self::assertNotNull($async, 'Une réponse différée doit partir en asyncSuccess.');
        self::assertSame('charge-1', $async->getOperationToken());
    }

    public function testAnOperationNobodyServesIsRefusedWithoutRetry(): void
    {
        // §2.4 et §1b.3 : NOT_IMPLEMENTED est terminale. Réessayable, la même opération
        // reviendrait toutes les ~9 s pendant tout son budget, pour la même réponse.
        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->startTask([])));

        $sent = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskFailed')
            ->willReturnCallback(function (RespondNexusTaskFailedRequest $request) use (&$sent): UnaryCall {
                $sent = $request;

                return $this->call(new RespondNexusTaskFailedResponse());
            });

        $this->worker(new NexusOperationRegistry())->pollOnce();

        self::assertSame(NexusHandlerErrorType::NotImplemented->value, $sent?->getError()?->getErrorType());
        self::assertSame(
            \Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_NON_RETRYABLE,
            $sent?->getError()?->getRetryBehavior(),
        );
    }

    public function testAHandlerThatRaisesIsReportedAsRetryableInternal(): void
    {
        // Ce que font tous les autres SDK : une exception ordinaire vaut INTERNAL. Un
        // gestionnaire qui veut un refus définitif doit le dire avec son type.
        $registry = new NexusOperationRegistry();
        $registry->register(
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            static fn(): NexusOperationResponse => throw new \RuntimeException('la base est tombée'),
        );

        $this->grpc->method('PollNexusTaskQueue')->willReturn($this->call($this->startTask([])));

        $sent = null;
        $this->grpc->expects($this->once())->method('RespondNexusTaskFailed')
            ->willReturnCallback(function (RespondNexusTaskFailedRequest $request) use (&$sent): UnaryCall {
                $sent = $request;

                return $this->call(new RespondNexusTaskFailedResponse());
            });

        $this->worker($registry)->pollOnce();

        self::assertSame(NexusHandlerErrorType::Internal->value, $sent?->getError()?->getErrorType());
        self::assertSame(
            \Temporal\Api\Enums\V1\NexusHandlerErrorRetryBehavior::NEXUS_HANDLER_ERROR_RETRY_BEHAVIOR_RETRYABLE,
            $sent?->getError()?->getRetryBehavior(),
        );
        self::assertStringContainsString('la base est tombée', (string) $sent?->getError()?->getFailure()?->getMessage());
    }

    private function worker(NexusOperationRegistry $registry): TemporalNexusWorker
    {
        return new TemporalNexusWorker(
            new WorkflowServiceNexusRpc($this->grpc),
            new TemporalConnection(target: 'localhost:7233', namespace: 'test'),
            $registry,
            'nexus-queue',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startTask(array $payload): PollNexusTaskQueueResponse
    {
        $start = new StartOperationRequest();
        $start->setService('billing');
        $start->setOperation('charge');
        $start->setCallback('temporal://system');
        $start->setPayload(JsonPlainPayload::encode($payload));

        $request = new NexusRequest();
        $request->setStartOperation($start);

        $task = new PollNexusTaskQueueResponse();
        $task->setTaskToken('jeton-de-tache');
        $task->setRequest($request);

        return $task;
    }

    private function call(mixed $response): UnaryCall
    {
        $call = $this->createMock(UnaryCall::class);
        $status = new \stdClass();
        $status->code = \Grpc\STATUS_OK;
        $status->details = '';
        $call->method('wait')->willReturn([$response, $status]);

        return $call;
    }
}
