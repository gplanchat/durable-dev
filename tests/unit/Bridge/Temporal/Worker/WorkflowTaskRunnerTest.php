<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Google\Protobuf\Any;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\Versioning\ChangePoint;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\TimeoutType;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Failure\V1\TimeoutFailureInfo;
use Temporal\Api\History\V1\ActivityTaskCompletedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskFailedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\ActivityTaskStartedEventAttributes;
use Temporal\Api\History\V1\ActivityTaskTimedOutEventAttributes;
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
use unit\Durable\Fixtures\SuiteActivities;

/**
 * Unit tests for WorkflowTaskRunner — fiber-based replay of Temporal workflow history.
 *
 * The gRPC client is mocked but NEVER called in these tests because the full history is
 * provided inline in PollWorkflowTaskQueueResponse (next_page_token = '' → no pagination).
 * This makes tests fast and deterministic without a running Temporal server.
 */
final class WorkflowTaskRunnerTest extends TestCase
{
    private WorkflowServiceClientInterface&MockObject $grpcClient;
    private TemporalHistoryCursor $cursor;
    private TemporalConnection $connection;

    protected function setUp(): void
    {
        $this->grpcClient = $this->createMock(WorkflowServiceClientInterface::class);
        $this->cursor = new TemporalHistoryCursor($this->grpcClient, 'test-namespace');
        $this->connection = new TemporalConnection('localhost:7233', 'test-namespace');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param list<HistoryEvent> $events */
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

    /** @param array<mixed> $input */
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
     * An update as it arrives: a protocol message on the task, not a history event.
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

    /**
     * A run left stuck by #544: its activity timed out, the next workflow task completed with no
     * command because the timeout went unread, and the run waited. Replayed now, its next workflow
     * task fails it: the history is the same, only the reading of TIMED_OUT changed.
     */
    public function testARunStuckOnAnUnreadActivityTimeoutFailsOnItsNextTask(): void
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory(
            'TimingOutWorkflow',
            static fn(array $payload)
            => static function (WorkflowEnvironment $env): string {
                return $env->await($env->activityStub(SuiteActivities::class)->doWork());
            },
        );

        $timedOut = self::makeEvent(7, EventType::EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT);
        $timedOut->setActivityTaskTimedOutEventAttributes(new ActivityTaskTimedOutEventAttributes([
            'scheduled_event_id' => 5,
            'failure' => new Failure(['timeout_failure_info' => new TimeoutFailureInfo(['timeout_type' => TimeoutType::TIMEOUT_TYPE_HEARTBEAT])]),
        ]));

        $result = $this->makeRunner($registry)->run(self::buildPoll('token-stuck', 'wf-stuck', 'TimingOutWorkflow', [
            self::makeStarted(1),
            self::makeEvent(2, EventType::EVENT_TYPE_WORKFLOW_TASK_SCHEDULED),
            self::makeEvent(3, EventType::EVENT_TYPE_WORKFLOW_TASK_STARTED),
            self::makeEvent(4, EventType::EVENT_TYPE_WORKFLOW_TASK_COMPLETED),
            self::makeActivityScheduled(5, 'slot-0'),
            self::makeEvent(6, EventType::EVENT_TYPE_ACTIVITY_TASK_STARTED),
            $timedOut,
            // The task the bug completed with no command.
            self::makeEvent(8, EventType::EVENT_TYPE_WORKFLOW_TASK_SCHEDULED),
            self::makeEvent(9, EventType::EVENT_TYPE_WORKFLOW_TASK_STARTED),
            self::makeEvent(10, EventType::EVENT_TYPE_WORKFLOW_TASK_COMPLETED),
            self::makeEvent(11, EventType::EVENT_TYPE_WORKFLOW_TASK_SCHEDULED),
            self::makeEvent(12, EventType::EVENT_TYPE_WORKFLOW_TASK_STARTED),
        ]));

        self::assertCount(1, $result->commands);
        self::assertSame(CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION, $result->commands[0]->getCommandType());
        self::assertStringContainsString('Activity heartbeat timeout exceeded.', (string) $result->commands[0]->getFailWorkflowExecutionCommandAttributes()?->getFailure()?->getMessage());
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
        /** @psalm-suppress TypeDoesNotContainType set inside the workflow closure, which Psalm reads as never run */
        self::assertSame(['value' => 42], $capturedSignal->value);
    }

    public function testASignalDeliveredAfterItsDeadlineDoesNotUndoTheTimeoutOnReplay(): void
    {
        // Verdict parity with the in-memory backend (WorkflowDeadlineTest): the Temporal history
        // is ordered by eventId, and a signal whose eventId is greater than that of the
        // TIMER_FIRED arrived too late for the wait the timer was bounding.
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

        /** @psalm-suppress TypeDoesNotContainType set inside the workflow closure, which Psalm reads as never run */
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

        /** @psalm-suppress TypeDoesNotContainType set inside the workflow closure, which Psalm reads as never run */
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
        // Parity with probe 1.3: acceptance and answer both leave on the current task, and it is
        // the handler's return that makes the outcome.
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

                return 'done';
            });

        $poll = self::buildPoll('token-upd', 'wf-upd', 'UpdatableWorkflow', [self::makeStarted(1)]);
        $poll->setMessages([self::makeUpdateMessage('upd-42', 'approve', ['by' => 'alice'], 2)]);

        $result = $this->makeRunner($registry)->run($poll);

        $ids = array_map(static fn(ProtocolMessage $m): string => $m->getId(), $result->messages);
        self::assertSame(['upd-42/accept', 'upd-42/complete'], $ids);

        // One command per message. Acceptance alone would leave the answer out of the sequence,
        // and the server would close the execution before delivering it.
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

        // The workflow ran to the end: the update unblocked it, not the other way round. And the
        // order matters — the server refuses any sequence where CompleteWorkflowExecution is not
        // the last.
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

    /**
     * #547: the attempt a failed activity reports is now the one the server ran. A workflow that
     * copies it into a later payload, in a run recorded under the old reading (attempt 1), diverges
     * on replay; UPGRADE.md says to drain such runs or branch on versionForChangeId. Kept to pin
     * that the divergence is loud, not a silently different payload.
     */
    public function testAnAttemptCopiedIntoAPayloadDivergesOnARunRecordedUnderTheOldReading(): void
    {
        $this->expectException(WorkflowTaskFailure::class);
        $this->expectExceptionMessageMatches('/Replay divergence at activity slot 1 .*attempt-1.*attempt-3/s');

        $this->replayRetriedThenFailed('attempt-1', static fn(DurableActivityFailedException $e, WorkflowEnvironment $env): string => 'attempt-' . $e->attempt());
    }

    /**
     * #547: a workflow that does not put the attempt into a payload replays such a run unchanged.
     */
    public function testAPlainReplayOfARetriedThenFailedActivityDoesNotDiverge(): void
    {
        $result = $this->replayRetriedThenFailed('x', static fn(DurableActivityFailedException $e, WorkflowEnvironment $env): string => 'x');

        self::assertSame([], array_map(static fn($c): int => $c->getCommandType(), array_filter(
            $result->commands,
            static fn($c): bool => CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION === $c->getCommandType(),
        )));
    }

    /**
     * #547: UPGRADE.md's way out for such a run, a change point that keeps the old reading for an
     * execution that started before it, replays the old history without diverging.
     */
    public function testTheVersionedWayOutReplaysARunRecordedUnderTheOldReading(): void
    {
        $result = $this->replayRetriedThenFailed('attempt-1', static fn(DurableActivityFailedException $e, WorkflowEnvironment $env): string => 'attempt-' . (
            ChangePoint::DEFAULT_VERSION === $env->version('real-activity-attempt', ChangePoint::DEFAULT_VERSION, 1) ? 1 : $e->attempt()
        ));

        self::assertSame([], array_filter($result->commands, static fn($c): bool => CommandType::COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION === $c->getCommandType()));
    }

    /**
     * double(2) ran three attempts and failed; the workflow caught it and scheduled greet(...),
     * recorded with `$recordedName`.
     *
     * @param \Closure(DurableActivityFailedException, WorkflowEnvironment): string $name
     */
    private function replayRetriedThenFailed(string $recordedName, \Closure $name): \Gplanchat\Bridge\Temporal\Worker\WorkflowTaskResult
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory('Probe', static fn(array $payload) => static function (WorkflowEnvironment $env) use ($name): string {
            $stub = $env->activityStub(SuiteActivities::class);

            try {
                return (string) $env->await($stub->double(2));
            } catch (DurableActivityFailedException $e) {
                return $env->await($stub->greet($name($e, $env)));
            }
        });

        $started = self::makeEvent(3, EventType::EVENT_TYPE_ACTIVITY_TASK_STARTED);
        $started->setActivityTaskStartedEventAttributes(new ActivityTaskStartedEventAttributes(['scheduled_event_id' => 2, 'attempt' => 3]));
        $failed = self::makeActivityFailed(4, 2, 'boom');
        $failed->getActivityTaskFailedEventAttributes()?->setStartedEventId(3);

        return $this->makeRunner($registry)->run(self::buildPoll('token-attempt', 'wf-attempt', 'Probe', [
            self::makeStarted(1),
            self::scheduledWithInput(2, 'act-1', 'double', ['value' => 2]),
            $started,
            $failed,
            self::scheduledWithInput(5, 'act-2', 'greet', ['name' => $recordedName]),
            self::makeEvent(6, EventType::EVENT_TYPE_WORKFLOW_TASK_SCHEDULED),
            self::makeEvent(7, EventType::EVENT_TYPE_WORKFLOW_TASK_STARTED),
        ]));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function scheduledWithInput(int $id, string $activityId, string $type, array $arguments): HistoryEvent
    {
        $e = self::makeActivityScheduled($id, $activityId);
        $attr = $e->getActivityTaskScheduledEventAttributes();
        \assert(null !== $attr);
        $attr->setActivityType(new ActivityType(['name' => $type]));
        $input = new Payloads();
        $input->setPayloads([JsonPlainPayload::encode(['payload' => $arguments])]);
        $attr->setInput($input);

        return $e;
    }
}
