<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Google\Protobuf\Any;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\History\V1\ActivityTaskCompletedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskFailedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\TimerFiredEventAttributes;
use Temporal\Api\History\V1\TimerStartedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionSignaledEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;
use Temporal\Api\Protocol\V1\Message as ProtocolMessage;
use Temporal\Api\Update\V1\Input as UpdateInput;
use Temporal\Api\Update\V1\Meta as UpdateMeta;
use Temporal\Api\Update\V1\Request as UpdateRequest;
use Temporal\Api\Update\V1\Response as UpdateResponse;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Unit tests for WorkflowTaskRunner — fiber-based replay of Temporal workflow history.
 *
 * The gRPC client is mocked but NEVER called in these tests because the full history is
 * provided inline in PollWorkflowTaskQueueResponse (next_page_token = '' → no pagination).
 * This makes tests fast and deterministic without a running Temporal server.
 */
#[RequiresPhpExtension('grpc')]
final class WorkflowTaskRunnerTest extends TestCase
{
    private WorkflowServiceClient $grpcClient;
    private TemporalHistoryCursor $cursor;
    private TemporalConnection $connection;

    protected function setUp(): void
    {
        $this->grpcClient = $this->createMock(WorkflowServiceClient::class);
        $this->cursor = new TemporalHistoryCursor($this->grpcClient, 'test-namespace');
        $this->connection = new TemporalConnection('localhost:7233', 'test-namespace');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function buildPoll(
        string $token,
        string $workflowId,
        string $workflowTypeName,
        array $events,
    ): PollWorkflowTaskQueueResponse {
        $history = new History();
        $history->setEvents($events);

        $exec = new WorkflowExecution();
        $exec->setWorkflowId($workflowId);

        $wfType = new WorkflowType();
        $wfType->setName($workflowTypeName);

        $poll = new PollWorkflowTaskQueueResponse();
        $poll->setTaskToken($token);
        $poll->setWorkflowExecution($exec);
        $poll->setWorkflowType($wfType);
        $poll->setHistory($history);
        $poll->setNextPageToken('');

        return $poll;
    }

    private static function makeEvent(int $id, int $type): HistoryEvent
    {
        $e = new HistoryEvent();
        $e->setEventId($id);
        $e->setEventType($type);

        return $e;
    }

    private static function makeStarted(int $id, array $input = []): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $attr = new WorkflowExecutionStartedEventAttributes();
        $ps = new Payloads();
        $ps->setPayloads([JsonPlainPayload::encode($input)]);
        $attr->setInput($ps);
        $e->setWorkflowExecutionStartedEventAttributes($attr);

        return $e;
    }

    private static function makeActivityScheduled(int $id, string $activityId): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $attr = new ActivityTaskScheduledEventAttributes();
        $attr->setActivityId($activityId);
        $e->setActivityTaskScheduledEventAttributes($attr);

        return $e;
    }

    private static function makeActivityCompleted(int $id, int $scheduledEventId, mixed $result): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_ACTIVITY_TASK_COMPLETED);
        $attr = new ActivityTaskCompletedEventAttributes();
        $attr->setScheduledEventId($scheduledEventId);
        $ps = new Payloads();
        $ps->setPayloads([JsonPlainPayload::encode($result)]);
        $attr->setResult($ps);
        $e->setActivityTaskCompletedEventAttributes($attr);

        return $e;
    }

    private static function makeActivityFailed(int $id, int $scheduledEventId, string $message): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_ACTIVITY_TASK_FAILED);
        $attr = new ActivityTaskFailedEventAttributes();
        $attr->setScheduledEventId($scheduledEventId);
        $failure = new Failure();
        $failure->setMessage($message);
        $attr->setFailure($failure);
        $e->setActivityTaskFailedEventAttributes($attr);

        return $e;
    }

    private static function makeTimerStarted(int $id, string $timerId): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_TIMER_STARTED);
        $attr = new TimerStartedEventAttributes();
        $attr->setTimerId($timerId);
        $e->setTimerStartedEventAttributes($attr);

        return $e;
    }

    private static function makeTimerFired(int $id, int $startedEventId): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_TIMER_FIRED);
        $attr = new TimerFiredEventAttributes();
        $attr->setStartedEventId($startedEventId);
        $e->setTimerFiredEventAttributes($attr);

        return $e;
    }

    private static function makeSignalEvent(int $id, string $signalName, mixed $payload): HistoryEvent
    {
        $e = self::makeEvent($id, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_SIGNALED);
        $attr = new WorkflowExecutionSignaledEventAttributes();
        $attr->setSignalName($signalName);
        $ps = new Payloads();
        $ps->setPayloads([JsonPlainPayload::encode($payload)]);
        $attr->setInput($ps);
        $e->setWorkflowExecutionSignaledEventAttributes($attr);

        return $e;
    }

    /**
     * Un update tel qu'il arrive : message de protocole sur la tâche, pas événement d'historique.
     */
    private static function makeUpdateMessage(string $updateId, string $name, mixed $args, int $sequencingEventId): ProtocolMessage
    {
        $input = new UpdateInput();
        $input->setName($name);
        $input->setArgs(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($args)));

        $request = new UpdateRequest();
        $request->setMeta(new UpdateMeta(['update_id' => $updateId, 'identity' => 'test']));
        $request->setInput($input);

        $body = new Any();
        $body->pack($request);

        $message = new ProtocolMessage();
        $message->setId($updateId . '/request');
        $message->setProtocolInstanceId($updateId);
        $message->setEventId($sequencingEventId);
        $message->setBody($body);

        return $message;
    }

    private function makeRunner(WorkflowRegistry $registry): WorkflowTaskRunner
    {
        return new WorkflowTaskRunner($this->cursor, $registry, $this->connection);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testEmptyTokenReturnsEmptyResult(): void
    {
        $registry = new WorkflowRegistry();
        $runner = $this->makeRunner($registry);

        $poll = self::buildPoll('', 'wf-1', 'MyWorkflow', []);
        $result = $runner->run($poll);

        self::assertEmpty($result->commands);
    }

    public function testWorkflowCompletesImmediately(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'ImmediateWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env) use ($payload): string {
                return 'done-' . $payload['key'];
            },
        );

        $runner = $this->makeRunner($registry);
        $poll = self::buildPoll('token-1', 'wf-1', 'ImmediateWorkflow', [
            self::makeStarted(1, ['key' => 'hello']),
        ]);

        $result = $runner->run($poll);

        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION, $result->commands[0]->getCommandType());
    }

    public function testWorkflowWithReplayedActivityCompletesSuccessfully(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'ActivityWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                $result = $env->await($env->activityStub(SuiteActivities::class)->greet('World'));

                return 'result: ' . $result;
            },
        );

        $runner = $this->makeRunner($registry);

        // History: activity was scheduled AND completed → full replay
        $poll = self::buildPoll('token-1', 'wf-1', 'ActivityWorkflow', [
            self::makeStarted(1),
            self::makeActivityScheduled(2, 'slot-0'),
            self::makeActivityCompleted(3, 2, 'Hello World'),
        ]);

        $result = $runner->run($poll);

        // Activity was replayed → workflow completes (no ScheduleActivity, only CompleteWorkflow)
        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION, $result->commands[0]->getCommandType());
    }

    public function testWorkflowWithNewActivityEmitsScheduleCommand(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'ActivityWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                return $env->await($env->activityStub(SuiteActivities::class)->greet('World'));
            },
        );

        $runner = $this->makeRunner($registry);

        // History: only STARTED — activity not yet scheduled in history → new command needed
        $poll = self::buildPoll('token-2', 'wf-2', 'ActivityWorkflow', [
            self::makeStarted(1),
        ]);

        $result = $runner->run($poll);

        // One ScheduleActivityTask command, no CompleteWorkflow yet
        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK, $result->commands[0]->getCommandType());
    }

    public function testWorkflowWithFailedActivityPropagatesException(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'FailingWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                return $env->await($env->activityStub(SuiteActivities::class)->doWork());
            },
        );

        $runner = $this->makeRunner($registry);

        $poll = self::buildPoll('token-3', 'wf-3', 'FailingWorkflow', [
            self::makeStarted(1),
            self::makeActivityScheduled(2, 'slot-0'),
            self::makeActivityFailed(3, 2, 'WorkActivity failed badly'),
        ]);

        $result = $runner->run($poll);

        // The exception from the failed activity propagates up → FailWorkflowExecution command
        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION, $result->commands[0]->getCommandType());
    }

    public function testWorkflowWithParallelActivitiesEmitsMultipleScheduleCommands(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'ParallelWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): array {
                return $env->await($env->all(
                    $env->activityStub(SuiteActivities::class)->taskA(),
                    $env->activityStub(SuiteActivities::class)->taskB(),
                ));
            },
        );

        $runner = $this->makeRunner($registry);

        // History: only STARTED — both activities not yet scheduled
        $poll = self::buildPoll('token-4', 'wf-4', 'ParallelWorkflow', [
            self::makeStarted(1),
        ]);

        $result = $runner->run($poll);

        // Both ScheduleActivityTask commands emitted in the same workflow task
        $scheduleCommands = array_filter(
            $result->commands,
            static fn($cmd) => $cmd->getCommandType() === CommandType::COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK,
        );
        self::assertCount(2, $scheduleCommands, 'Two parallel activities must be scheduled in a single task');
    }

    public function testWorkflowWithTimerReplayCompletesWhenFired(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'TimerWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                $env->sleep(60);

                return 'after-timer';
            },
        );

        $runner = $this->makeRunner($registry);

        // History: timer was started AND fired → full replay
        $poll = self::buildPoll('token-5', 'wf-5', 'TimerWorkflow', [
            self::makeStarted(1),
            self::makeTimerStarted(2, 'timer-0'),
            self::makeTimerFired(3, 2),
        ]);

        $result = $runner->run($poll);

        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION, $result->commands[0]->getCommandType());
    }

    public function testWorkflowWithTimerNotYetFiredEmitsStartTimerCommand(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'TimerWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                $env->sleep(60);

                return 'after-timer';
            },
        );

        $runner = $this->makeRunner($registry);

        // History: only STARTED — timer not yet issued
        $poll = self::buildPoll('token-6', 'wf-6', 'TimerWorkflow', [
            self::makeStarted(1),
        ]);

        $result = $runner->run($poll);

        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_START_TIMER, $result->commands[0]->getCommandType());
    }

    public function testWorkflowHandlerThrowsProducesFailWorkflowCommand(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'BrokenWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): never {
                throw new \RuntimeException('Unhandled workflow error');
            },
        );

        $runner = $this->makeRunner($registry);
        $poll = self::buildPoll('token-7', 'wf-7', 'BrokenWorkflow', [
            self::makeStarted(1),
        ]);

        $result = $runner->run($poll);

        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION, $result->commands[0]->getCommandType());
    }

    public function testSignalIsReadableAfterReplay(): void
    {
        $capturedSignal = new \stdClass();
        $capturedSignal->value = null;

        $registry = new WorkflowRegistry();
        $registry->registerFactory('SignaledWorkflow', function (array $payload) use ($capturedSignal) {
            return function (WorkflowEnvironment $env) use ($capturedSignal): string {
                $received = [];
                $env->onSignal('mySignal', static function (array $payload) use (&$received): void {
                    $received[] = $payload;
                });
                $env->await(static function () use (&$received): bool {
                    return [] !== $received;
                });
                $capturedSignal->value = array_shift($received);

                return 'received';
            };
        });

        $runner = $this->makeRunner($registry);

        // History: STARTED + SIGNALED → the signal is present → replay completes
        $poll = self::buildPoll('token-8', 'wf-8', 'SignaledWorkflow', [
            self::makeStarted(1),
            self::makeSignalEvent(2, 'mySignal', ['value' => 42]),
        ]);

        $result = $runner->run($poll);

        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION, $result->commands[0]->getCommandType());
        self::assertSame(['value' => 42], $capturedSignal->value);
    }

    public function testASignalDeliveredAfterItsDeadlineDoesNotUndoTheTimeoutOnReplay(): void
    {
        // Parité de verdict avec le backend in-memory (WorkflowDeadlineTest) : l'historique
        // Temporal est ordonné par eventId, et un signal d'eventId supérieur à celui du
        // TIMER_FIRED est arrivé trop tard pour l'attente que le minuteur bornait.
        $verdict = new \stdClass();
        $verdict->value = null;

        $runner = $this->makeRunner($this->deadlineRegistry($verdict));
        $poll = self::buildPoll('token-deadline-1', 'wf-deadline-1', 'DeadlineWorkflow', [
            self::makeStarted(1),
            self::makeTimerStarted(2, 'timer-a'),
            self::makeTimerFired(3, 2),
            self::makeSignalEvent(4, 'approve', ['by' => 'late']),
        ]);

        $result = $runner->run($poll);

        self::assertSame(['timeout'], $verdict->value);
        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION, $result->commands[0]->getCommandType());
    }

    public function testASignalRecordedBeforeTheDeadlineFiredStillSettlesTheWait(): void
    {
        $verdict = new \stdClass();
        $verdict->value = null;

        $runner = $this->makeRunner($this->deadlineRegistry($verdict));
        $poll = self::buildPoll('token-deadline-2', 'wf-deadline-2', 'DeadlineWorkflow', [
            self::makeStarted(1),
            self::makeTimerStarted(2, 'timer-a'),
            self::makeSignalEvent(3, 'approve', ['by' => 'alice']),
            self::makeTimerFired(4, 2),
        ]);

        $runner->run($poll);

        self::assertSame(['signal', ['by' => 'alice']], $verdict->value);
    }

    private function deadlineRegistry(\stdClass $verdict): WorkflowRegistry
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory('DeadlineWorkflow', static fn(array $payload)
            => static function (WorkflowEnvironment $env) use ($verdict): array {
                $approvals = [];
                $env->onSignal('approve', static function (array $payload) use (&$approvals): void {
                    $approvals[] = $payload;
                });

                try {
                    $env->await(static function () use (&$approvals): bool {
                        return [] !== $approvals;
                    }, Duration::seconds(30));
                    $verdict->value = ['signal', array_shift($approvals)];
                } catch (DeadlineExceededException) {
                    $verdict->value = ['timeout'];
                }

                return $verdict->value;
            });

        return $registry;
    }

    public function testAnUpdateIsAcceptedAndAnsweredOnTheSameTask(): void
    {
        // Parité avec la sonde 1.3 : acceptation et réponse repartent sur la tâche courante, et
        // c'est le retour du handler qui fait l'issue.
        $registry = new WorkflowRegistry();
        $registry->registerFactory('UpdatableWorkflow', static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                $answered = false;
                $env->onUpdate('approve', static function (array $args) use (&$answered): array {
                    $answered = true;

                    return ['ok' => true, 'by' => $args['by']];
                });
                $env->await(static function () use (&$answered): bool {
                    return $answered;
                });

                return 'terminé';
            });

        $poll = self::buildPoll('token-upd', 'wf-upd', 'UpdatableWorkflow', [self::makeStarted(1)]);
        $poll->setMessages([self::makeUpdateMessage('upd-42', 'approve', ['by' => 'alice'], 2)]);

        $result = $this->makeRunner($registry)->run($poll);

        $ids = array_map(static fn(ProtocolMessage $m): string => $m->getId(), $result->messages);
        self::assertSame(['upd-42/accept', 'upd-42/complete'], $ids);

        // Une commande par message. L'acceptation seule laisserait la réponse hors de la
        // séquence, et le serveur clôrait l'exécution avant de la délivrer.
        $protocolCommands = array_values(array_filter(
            $result->commands,
            static fn($c): bool => CommandType::COMMAND_TYPE_PROTOCOL_MESSAGE === $c->getCommandType(),
        ));
        self::assertSame(
            ['upd-42/accept', 'upd-42/complete'],
            array_map(static fn($c): ?string => $c->getProtocolMessageCommandAttributes()?->getMessageId(), $protocolCommands),
        );

        $response = new UpdateResponse();
        $response->mergeFromString($result->messages[1]->getBody()->getValue());
        $success = $response->getOutcome()?->getSuccess();
        self::assertNotNull($success);
        self::assertSame(['ok' => true, 'by' => 'alice'], JsonPlainPayload::decode($success->getPayloads()[0]));

        // Le workflow est allé au bout : l'update l'a débloqué, pas l'inverse. Et l'ordre compte —
        // le serveur refuse toute séquence où CompleteWorkflowExecution n'est pas la dernière.
        $types = array_map(static fn($c): int => $c->getCommandType(), $result->commands);
        self::assertSame(
            [
                CommandType::COMMAND_TYPE_PROTOCOL_MESSAGE,
                CommandType::COMMAND_TYPE_PROTOCOL_MESSAGE,
                CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION,
            ],
            $types,
        );
    }

    public function testSignalNotYetReceivedSuspendsWorkflow(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'SignaledWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                $received = false;
                $env->onSignal('mySignal', static function (array $payload) use (&$received): void {
                    $received = true;
                });
                $env->await(static function () use (&$received): bool {
                    return $received;
                });

                return 'received';
            },
        );

        $runner = $this->makeRunner($registry);

        // History: only STARTED — no signal yet
        $poll = self::buildPoll('token-9', 'wf-9', 'SignaledWorkflow', [
            self::makeStarted(1),
        ]);

        $result = $runner->run($poll);

        // Workflow is suspended waiting for signal → no commands emitted
        self::assertEmpty($result->commands, 'No commands when workflow is suspended waiting for signal');
    }
}
