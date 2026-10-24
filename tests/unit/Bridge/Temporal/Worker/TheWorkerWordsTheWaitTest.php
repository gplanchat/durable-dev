<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Worker;

use Google\Protobuf\Duration as ProtobufDuration;
use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Observation\Wait;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Common\V1\Payloads;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\History;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\TimerStartedEventAttributes;
use Temporal\Api\History\V1\WorkflowExecutionStartedEventAttributes;
use Temporal\Api\History\V1\WorkflowPropertiesModifiedEventAttributes;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * #514: a suspending task words its wait as the core does ({@see \Gplanchat\Durable\Observation\WaitReason}),
 * whether the activity or the timer was scheduled in this task or in an earlier one.
 */
final class TheWorkerWordsTheWaitTest extends TestCase
{
    public function testAnActivityScheduledInThisTaskIsNamed(): void
    {
        $commands = $this->task('ActivityWorkflow', [self::started(1)]);

        self::assertSame(CommandType::COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK, $commands[0]->getCommandType(), 'the memo does not push the schedule out of its slot');
        self::assertSame(Wait::activity('greet')->toStorage(), self::waitIn($commands));
    }

    public function testAnActivityScheduledInAnEarlierTaskIsStillNamed(): void
    {
        $commands = $this->task('ActivityWorkflow', [self::started(1), self::activityScheduled(5, 'greet')]);

        self::assertSame(Wait::activity('greet')->toStorage(), self::waitIn($commands));
        self::assertCount(1, $commands, 'no second schedule on replay');
    }

    /**
     * The deadline counts from the task that started the timer, the one clock both that task and the
     * later ones read: TIMER_STARTED is written a moment after, and the worker's own clock drifts.
     */
    public function testATimerIsDueAtItsTaskStartPlusItsTimeout(): void
    {
        $commands = $this->task('TimerWorkflow', [self::started(1), self::taskStarted(3, 1_790_000_000), self::timerStarted(5, 1_790_000_001, 60), self::taskStarted(7, 1_790_000_030)]);

        self::assertSame(self::dueAt(1_790_000_060), self::waitIn($commands));
    }

    /**
     * The summary never reaches the server, so a later task cannot read it back: the task that
     * starts the timer leaves it out as well, or the same wait would change words and be written twice.
     */
    public function testATimerIsWordedAlikeOnTheTaskThatStartsIt(): void
    {
        $waitingOn = self::waitIn($this->task('SummarisedTimerWorkflow', [self::started(1), self::taskStarted(3, 1_790_000_000)]));

        self::assertSame(self::dueAt(1_790_000_060), $waitingOn, 'the same words as the later tasks read back');
    }

    public function testAConditionIsNamedByItsLabel(): void
    {
        self::assertSame('the stock comes back', self::waitIn($this->task('ConditionWorkflow', [self::started(1)])));
    }

    public function testTheSameWaitIsNotWrittenTwiceAndTheModifiedEventReplays(): void
    {
        $commands = $this->task('ActivityWorkflow', [self::started(1), self::activityScheduled(5, 'greet'), self::waitRecorded(6, Wait::activity('greet')->toStorage())]);

        self::assertSame([], $commands, 'an unchanged wait costs no history event, and the replay schedules nothing again');
    }

    /**
     * @param list<HistoryEvent> $events
     *
     * @return list<Command>
     */
    private function task(string $workflow, array $events): array
    {
        $registry = new WorkflowRegistry();
        $registry->registerFactory('ActivityWorkflow', static fn(array $payload) => static fn(WorkflowEnvironment $env): string => $env->await($env->activityStub(SuiteActivities::class)->greet('World')));
        $registry->registerFactory('TimerWorkflow', static fn(array $payload) => static function (WorkflowEnvironment $env): string {
            $env->sleep(60);

            return 'after';
        });
        $registry->registerFactory('SummarisedTimerWorkflow', static fn(array $payload) => static function (WorkflowEnvironment $env): string {
            $env->sleep(60, timerSummary: 'grace period');

            return 'after';
        });
        $registry->registerFactory('ConditionWorkflow', static fn(array $payload) => static fn(WorkflowEnvironment $env): mixed => $env->await(static fn(): bool => false, label: 'the stock comes back'));

        $history = new History();
        $history->setEvents($events);
        $poll = new PollWorkflowTaskQueueResponse();
        $poll->setTaskToken('token');
        $poll->setWorkflowExecution(new WorkflowExecution(['workflow_id' => 'wf-1']));
        $poll->setWorkflowType(new WorkflowType(['name' => $workflow]));
        $poll->setHistory($history);

        $connection = new TemporalConnection('localhost:7233', 'test-namespace');
        $runner = new WorkflowTaskRunner(new TemporalHistoryCursor($this->createMock(WorkflowServiceClientInterface::class), 'test-namespace'), $registry, $connection);

        return $runner->run($poll)->commands;
    }

    /**
     * @param list<Command> $commands
     */
    private static function waitIn(array $commands): ?string
    {
        foreach ($commands as $command) {
            $field = $command->getModifyWorkflowPropertiesCommandAttributes()?->getUpsertedMemo()?->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_WAITING_ON] ?? null;
            if (null !== $field) {
                $waitingOn = JsonPlainPayload::decode($field);
                self::assertTrue(null === $waitingOn || \is_string($waitingOn));

                return $waitingOn;
            }
        }
        self::fail('no durableWaitingOn memo in the task');
    }

    private static function started(int $id): HistoryEvent
    {
        $input = new Payloads();
        $input->setPayloads([JsonPlainPayload::encode([])]);

        return self::event($id, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED)
            ->setWorkflowExecutionStartedEventAttributes(new WorkflowExecutionStartedEventAttributes(['input' => $input]));
    }

    private static function activityScheduled(int $id, string $name): HistoryEvent
    {
        return self::event($id, EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED)
            ->setActivityTaskScheduledEventAttributes(new ActivityTaskScheduledEventAttributes(['activity_id' => 'act-1', 'activity_type' => new ActivityType(['name' => $name])]));
    }

    private static function timerStarted(int $id, int $at, int $seconds): HistoryEvent
    {
        return self::event($id, EventType::EVENT_TYPE_TIMER_STARTED)
            ->setEventTime(new Timestamp(['seconds' => $at]))
            ->setTimerStartedEventAttributes(new TimerStartedEventAttributes(['timer_id' => 'timer-1', 'start_to_fire_timeout' => new ProtobufDuration(['seconds' => $seconds])]));
    }

    private static function waitRecorded(int $id, string $waitingOn): HistoryEvent
    {
        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_WAITING_ON] = JsonPlainPayload::encode($waitingOn);

        return self::event($id, EventType::EVENT_TYPE_WORKFLOW_PROPERTIES_MODIFIED)
            ->setWorkflowPropertiesModifiedEventAttributes(new WorkflowPropertiesModifiedEventAttributes(['upserted_memo' => $memo]));
    }

    private static function taskStarted(int $id, int $at): HistoryEvent
    {
        return self::event($id, EventType::EVENT_TYPE_WORKFLOW_TASK_STARTED)->setEventTime(new Timestamp(['seconds' => $at]));
    }

    private static function dueAt(int $at): string
    {
        return Wait::timer('', $at)->toStorage();
    }

    private static function event(int $id, int $type): HistoryEvent
    {
        return new HistoryEvent(['event_id' => $id, 'event_type' => $type]);
    }
}
