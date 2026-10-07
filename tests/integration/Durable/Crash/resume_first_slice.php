<?php

declare(strict_types=1);

/**
 * One step of {@see integration\Durable\Crash\ResumeDispatchedFirstCrashTest}, as a whole PHP
 * process over one SQLite file: the journal, the metadata, and the two queues of
 * {@see integration\Durable\Crash\SqliteTestQueues}.
 *
 * Usage: php resume_first_slice.php <journal.sqlite>
 *        start|start-parent|start-timer|workflow|activity|timer|status|count-resumes
 *
 * Environment:
 *   SLICE_LOG   appended to, one line per activity actually executed
 *   SLICE_KILL  before-append | after-append (an activity's outcome), after-child-append (a child's
 *               outcome in its parent), after-timer-append (TimerCompleted): SIGKILL the process
 *   SLICE_ACK   on-dequeue: acknowledge the message before processing it, as an inline drain does,
 *               so that nothing redelivers it
 *   SLICE_EXECUTION  the run `status` reports on; the activity bench's by default
 *   SLICE_ONE   1: the `workflow` step handles one resume and stops, instead of draining the queue
 *
 * Exit codes: 0 done, 3 a resume is waiting for its outcome, 4 usage. A SIGKILL leaves none.
 */

use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Exception\ResumeArrivedBeforeItsOutcome;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use integration\Durable\Crash\CrashBenchActivities;
use integration\Durable\Crash\SqliteTestQueues;
use integration\Durable\Support\Workflow\ChildMiniWorkflow;
use integration\Durable\Support\Workflow\ParentOfAsyncChildWorkflow;

require __DIR__ . '/../../../../vendor/autoload.php';

const EXECUTION = '01900000-0000-7000-8000-00000000d050';

[$journalPath, $step] = [$argv[1] ?? null, $argv[2] ?? null];
if (null === $journalPath || !\in_array($step, ['start', 'start-parent', 'start-timer', 'workflow', 'activity', 'timer', 'status', 'count-resumes'], true)) {
    fwrite(STDERR, "usage: resume_first_slice.php <journal.sqlite> start|start-parent|start-timer|workflow|activity|timer|status|count-resumes\n");
    exit(4);
}

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $journalPath]);
$schema = new DurableSchema($connection);
$journal = new DbalEventStore($connection, $schema);
$metadata = new DbalWorkflowMetadataStore($connection, $schema);
$queues = new SqliteTestQueues($connection, $metadata);

$executor = new RegistryActivityExecutor();
$executor->register('bench.first', static function (): string {
    file_put_contents((string) getenv('SLICE_LOG'), "charge\n", FILE_APPEND | LOCK_EX);

    return 'ch_1';
});

// SIGKILL and not exit(): no destructor, no shutdown function, nothing flushed.
$killing = new class ($journal, (string) getenv('SLICE_KILL')) implements EventStoreInterface {
    public function __construct(private readonly EventStoreInterface $inner, private readonly string $kill) {}

    public function append(Event $event): void
    {
        if ($event instanceof ActivityCompleted && 'before-append' === $this->kill) {
            posix_kill(posix_getpid(), \SIGKILL);
        }
        $this->inner->append($event);
        if (($event instanceof ActivityCompleted && 'after-append' === $this->kill)
            || ($event instanceof ChildWorkflowCompleted && 'after-child-append' === $this->kill)
            || ($event instanceof TimerCompleted && 'after-timer-append' === $this->kill)) {
            posix_kill(posix_getpid(), \SIGKILL);
        }
    }

    public function readStream(ExecutionId $executionId): iterable
    {
        return $this->inner->readStream(ExecutionId::fromString($executionId->toString()));
    }

    public function readStreamWithRecordedAt(ExecutionId $executionId): iterable
    {
        return $this->inner->readStreamWithRecordedAt(ExecutionId::fromString($executionId->toString()));
    }

    public function countEventsInStream(ExecutionId $executionId): int
    {
        return $this->inner->countEventsInStream(ExecutionId::fromString($executionId->toString()));
    }
};
$runtime = new ExecutionRuntime($killing, $queues, $executor, 0, null, true);
$acksOnDequeue = 'on-dequeue' === getenv('SLICE_ACK');

switch ($step) {
    case 'start':
        $queues->dispatchNewWorkflowRun(ExecutionId::fromString(EXECUTION), 'resume-first', []);
        exit(0);

    case 'start-parent':
        $queues->dispatchNewWorkflowRun(ExecutionId::fromString('parent'), 'ParentOfAsyncChild', []);
        exit(0);

    case 'start-timer':
        $queues->dispatchNewWorkflowRun(ExecutionId::fromString('timer'), 'resume-timer', []);
        exit(0);

    case 'status':
        echo ($metadata->get(ExecutionId::fromString(getenv('SLICE_EXECUTION') ?: EXECUTION))['completed'] ?? false) ? 'completed' : 'running', "\n";
        exit(0);

    case 'count-resumes':
        echo $queues->count('resumes'), "\n";
        exit(0);

    case 'workflow':
        $registry = new WorkflowRegistry();
        $registry->registerFactory('resume-first', static fn(array $payload) => static fn(WorkflowEnvironment $env): string => $env->await($env->activityStub(CrashBenchActivities::class)->first('a')));
        $registry->registerFactory('resume-timer', static fn(array $payload) => static function (WorkflowEnvironment $env): string {
            $env->sleep(0.2);

            return 'woke';
        });
        $registry->registerClass(ParentOfAsyncChildWorkflow::class);
        $registry->registerClass(ChildMiniWorkflow::class);
        $links = new DbalChildWorkflowParentLinkStore($connection, $schema);
        $handler = new ResumeWorkflowHandler(
            new ExecutionEngine(
                $killing,
                $runtime,
                new ChildWorkflowRunner($killing, $runtime, $registry, $executor, 0, true, $queues, $links, metadataStore: $metadata),
                new ParentChildWorkflowCoordinator($killing, $queues),
            ),
            $registry,
            $metadata,
            $queues,
            $killing,
            $links,
            $queues,
            new WorkflowDefinitionLoader(),
        );
        while (null !== ($taken = $queues->take('resumes'))) {
            [$id, $message] = $taken;
            \assert($message instanceof ResumeWorkflowMessage);
            if ($acksOnDequeue) {
                $queues->ack($id);
            }

            try {
                $handler($message);
            } catch (ResumeArrivedBeforeItsOutcome) {
                exit(3); // left in the queue: the transport's retry is the wait
            }
            $queues->ack($id);
            if ('1' === getenv('SLICE_ONE')) {
                break;
            }
        }
        exit(0);

    case 'activity':
        $taken = $queues->take('activities');
        if (null === $taken) {
            exit(0);
        }
        [$id, $message] = $taken;
        \assert($message instanceof ActivityMessage);
        if ($acksOnDequeue) {
            $queues->ack($id);
        }
        $heartbeat = new class implements ActivityHeartbeatSenderInterface {
            public function sendHeartbeat(mixed $details = null): bool
            {
                return true;
            }

            public function isCancellationRequested(): bool
            {
                return false;
            }
        };
        (new ActivityMessageProcessor($killing, $queues, $executor, $queues, $heartbeat))->process($message);
        $queues->ack($id);
        exit(0);

    case 'timer':
        $taken = $queues->take('timers');
        if (null === $taken) {
            exit(0);
        }
        [$id, $message] = $taken;
        \assert($message instanceof FireWorkflowTimersMessage);
        if ($acksOnDequeue) {
            $queues->ack($id);
        }
        (new FireWorkflowTimersHandler($killing, $runtime, $queues, $queues))($message);
        $queues->ack($id);
        exit(0);
}
