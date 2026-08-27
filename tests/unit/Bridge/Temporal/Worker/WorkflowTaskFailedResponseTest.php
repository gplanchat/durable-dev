<?php

declare(strict_types=1);

namespace unit\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use Grpc\UnaryCall;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskFailedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskFailedResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Échouer la **tâche** de workflow, et non l'exécution.
 *
 * Mesuré contre un vrai serveur (sonde 1.2 de `workflow-replay-divergence-guard`) : lever depuis le
 * code de workflow produit `WORKFLOW_TASK_COMPLETED` puis `WORKFLOW_EXECUTION_FAILED`, et remettre
 * l'ancien code ne ressuscite rien. `RespondWorkflowTaskFailed` n'existait que comme stub généré.
 *
 * Sans ce chemin, une garde de replay ne peut qu'échanger une corruption silencieuse contre une
 * exécution morte. Avec lui, l'historique reste intact et la tâche est rejouée.
 */
final class WorkflowTaskFailedResponseTest extends TestCase
{
    private WorkflowServiceClient $grpcClient;
    private TemporalConnection $connection;

    protected function setUp(): void
    {
        $this->grpcClient = $this->createMock(WorkflowServiceClient::class);
        $this->connection = new TemporalConnection('localhost:7233', 'test-namespace');
    }

    public function testWorkflowTaskFailureRespondsTaskFailedAndNeverCompletes(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'DivergentWorkflow',
            static fn(array $payload) => static function (WorkflowEnvironment $env): never {
                throw new WorkflowTaskFailure('replay divergence at activity slot 2');
            },
        );

        $poll = $this->buildPoll('token-div', 'wf-div', 'DivergentWorkflow');

        $this->grpcClient
            ->expects($this->once())
            ->method('PollWorkflowTaskQueue')
            ->willReturn($this->makeUnaryCallReturning($poll));

        // Le point de tout l'exercice : aucune commande n'est envoyée, donc aucune commande
        // d'échec de workflow. L'exécution n'apprend rien de cette tentative.
        $this->grpcClient
            ->expects($this->never())
            ->method('RespondWorkflowTaskCompleted');

        $captured = null;
        $this->grpcClient
            ->expects($this->once())
            ->method('RespondWorkflowTaskFailed')
            ->willReturnCallback(function (RespondWorkflowTaskFailedRequest $req) use (&$captured) {
                $captured = $req;

                return $this->makeUnaryCallReturning(new RespondWorkflowTaskFailedResponse());
            });

        $processor = new WorkflowTaskProcessor(
            $this->grpcClient,
            $this->connection,
            new WorkflowTaskRunner(new TemporalHistoryCursor($this->grpcClient, 'test-namespace'), $registry, $this->connection),
        );

        self::assertTrue($processor->processOne());

        self::assertNotNull($captured);
        self::assertSame('token-div', $captured->getTaskToken());
        self::assertSame('test-namespace', $captured->getNamespace());
        self::assertStringContainsString(
            'replay divergence at activity slot 2',
            $captured->getFailure()?->getMessage() ?? '',
            'Le message de la garde doit voyager : sans lui, la tâche échoue sans dire pourquoi.',
        );
    }

    public function testAnOrdinaryThrowStillFailsTheExecution(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'BoomWorkflow',
            static fn(array $payload) => static function (WorkflowEnvironment $env): never {
                throw new \DomainException('métier cassé');
            },
        );

        $poll = $this->buildPoll('token-boom', 'wf-boom', 'BoomWorkflow');

        $this->grpcClient
            ->expects($this->once())
            ->method('PollWorkflowTaskQueue')
            ->willReturn($this->makeUnaryCallReturning($poll));

        // La distinction est le sujet : seule `WorkflowTaskFailure` échoue la tâche.
        $this->grpcClient
            ->expects($this->never())
            ->method('RespondWorkflowTaskFailed');

        $this->grpcClient
            ->expects($this->once())
            ->method('RespondWorkflowTaskCompleted')
            ->willReturnCallback(fn() => $this->makeUnaryCallReturning(
                new \Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedResponse(),
            ));

        $processor = new WorkflowTaskProcessor(
            $this->grpcClient,
            $this->connection,
            new WorkflowTaskRunner(new TemporalHistoryCursor($this->grpcClient, 'test-namespace'), $registry, $this->connection),
        );

        self::assertTrue($processor->processOne());
    }

    private function buildPoll(string $token, string $workflowId, string $type): PollWorkflowTaskQueueResponse
    {
        $started = new HistoryEvent();
        $started->setEventId(1);
        $started->setEventType(EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $started->setWorkflowExecutionStartedEventAttributes(new WorkflowExecutionStartedEventAttributes());

        $history = new History();
        $history->setEvents([$started]);

        $exec = new WorkflowExecution();
        $exec->setWorkflowId($workflowId);

        $wfType = new WorkflowType();
        $wfType->setName($type);

        $poll = new PollWorkflowTaskQueueResponse();
        $poll->setTaskToken($token);
        $poll->setWorkflowExecution($exec);
        $poll->setWorkflowType($wfType);
        $poll->setHistory($history);
        $poll->setNextPageToken('');

        return $poll;
    }

    private function makeUnaryCallReturning(object $response): UnaryCall
    {
        $status = new \stdClass();
        $status->code = \Grpc\STATUS_OK;
        $status->details = '';

        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn([$response, $status]);

        return $call;
    }
}
