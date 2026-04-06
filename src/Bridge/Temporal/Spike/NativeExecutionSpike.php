<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Spike;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Google\Protobuf\Duration;
use Temporal\Api\Command\V1\Command;
use Temporal\Api\Command\V1\CompleteWorkflowExecutionCommandAttributes;
use Temporal\Api\Command\V1\ScheduleActivityTaskCommandAttributes;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\CommandType;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollActivityTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondActivityTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Minimal end-to-end proof of **native** Temporal execution (no journal signals):
 * StartWorkflow → ScheduleActivityTask → activity worker → CompleteWorkflow.
 *
 * See **DUR024**. Task queue and type names are spike-specific; production uses the interpreter design.
 */
final class NativeExecutionSpike
{
    /** gRPC call deadline (microseconds) — long polls require an explicit timeout (Temporal / grpc-php). */
    private const GRPC_LONG_POLL_TIMEOUT_US = 120_000_000;

    private const GRPC_SHORT_TIMEOUT_US = 60_000_000;

    public const WORKFLOW_TYPE = 'DurableNativeSpikeWorkflow';

    public const WORKFLOW_TASK_QUEUE = 'durable-native-spike-workflow';

    public const ACTIVITY_TYPE = 'durableNativeSpikeEcho';

    public const ACTIVITY_TASK_QUEUE = 'durable-native-spike-activity';

    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly WorkflowServiceActivityRpc $activityRpc,
        private readonly TemporalConnection $settings,
    ) {
    }

    public static function create(TemporalConnection $settings): self
    {
        $client = WorkflowServiceClientFactory::create($settings);

        return new self($client, new WorkflowServiceActivityRpc($client), $settings);
    }

    /**
     * Runs one spike workflow to completion and returns the Temporal run id.
     *
     * @throws \RuntimeException on gRPC or protocol errors
     */
    public function run(string $workflowId): string
    {
        $ns = $this->settings->namespace;
        $identity = $this->settings->identity;

        $start = new StartWorkflowExecutionRequest();
        $start->setNamespace($ns);
        $start->setWorkflowId($workflowId);
        $start->setWorkflowType(new \Temporal\Api\Common\V1\WorkflowType(['name' => self::WORKFLOW_TYPE]));
        $start->setTaskQueue(new TaskQueue(['name' => self::WORKFLOW_TASK_QUEUE]));
        $start->setIdentity($identity);

        $runTimeout = new Duration();
        $runTimeout->setSeconds(120);
        $start->setWorkflowRunTimeout($runTimeout);

        $callStart = $this->client->StartWorkflowExecution($start, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]);
        $started = GrpcUnary::wait($callStart);
        $runId = (string) $started->getRunId();

        $poll1 = $this->pollWorkflowOnce($ns, $identity);
        if ('' === $poll1->getTaskToken()) {
            throw new \RuntimeException('Expected a workflow task with non-empty task token.');
        }

        $scheduleCmd = $this->buildScheduleActivityCommand();
        $this->respondWorkflowTask($ns, $identity, $poll1->getTaskToken(), [$scheduleCmd], false);

        $this->pollActivityAndComplete($ns, $identity);

        $poll2 = $this->pollWorkflowOnce($ns, $identity);

        if ('' === $poll2->getTaskToken()) {
            throw new \RuntimeException('Expected second workflow task after activity completion.');
        }

        $completeCmd = new Command();
        $completeCmd->setCommandType(CommandType::COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION);
        $completeAttrs = new CompleteWorkflowExecutionCommandAttributes();
        $completeAttrs->setResult(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode(['spike' => 'done'])));
        $completeCmd->setCompleteWorkflowExecutionCommandAttributes($completeAttrs);

        $this->respondWorkflowTask($ns, $identity, $poll2->getTaskToken(), [$completeCmd], false);

        return $runId;
    }

    private function pollActivityAndComplete(string $namespace, string $identity): void
    {
        $req = new PollActivityTaskQueueRequest();
        $req->setNamespace($namespace);
        $req->setTaskQueue(new TaskQueue(['name' => self::ACTIVITY_TASK_QUEUE]));
        $req->setIdentity($identity);

        $resp = null;
        for ($i = 0; $i < 60; ++$i) {
            $resp = $this->activityRpc->pollActivityTaskQueue($req);
            if ('' !== $resp->getTaskToken()) {
                break;
            }
            usleep(100_000);
        }
        if (!$resp instanceof PollActivityTaskQueueResponse || '' === $resp->getTaskToken()) {
            throw new \RuntimeException('Activity task poll timed out or empty.');
        }

        $done = new RespondActivityTaskCompletedRequest();
        $done->setTaskToken($resp->getTaskToken());
        $done->setNamespace($namespace);
        $done->setIdentity($identity);
        $done->setResult(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode(['echo' => 'ok'])));

        $this->activityRpc->respondActivityTaskCompleted($done);
    }

    /**
     * @param list<Command> $commands
     */
    private function respondWorkflowTask(
        string $namespace,
        string $identity,
        string $taskToken,
        array $commands,
        bool $returnNewWorkflowTask,
    ): RespondWorkflowTaskCompletedResponse {
        $req = new RespondWorkflowTaskCompletedRequest();
        $req->setTaskToken($taskToken);
        $req->setNamespace($namespace);
        $req->setIdentity($identity);
        $req->setCommands($commands);
        $req->setReturnNewWorkflowTask($returnNewWorkflowTask);

        $call = $this->client->RespondWorkflowTaskCompleted($req, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]);
        $out = GrpcUnary::wait($call);
        if (!$out instanceof RespondWorkflowTaskCompletedResponse) {
            throw new \RuntimeException('Unexpected RespondWorkflowTaskCompleted response type.');
        }

        return $out;
    }

    private function pollWorkflowOnce(string $namespace, string $identity): PollWorkflowTaskQueueResponse
    {
        $req = new PollWorkflowTaskQueueRequest();
        $req->setNamespace($namespace);
        $req->setTaskQueue(new TaskQueue(['name' => self::WORKFLOW_TASK_QUEUE]));
        $req->setIdentity($identity);

        $resp = null;
        for ($i = 0; $i < 120; ++$i) {
            $call = $this->client->PollWorkflowTaskQueue($req, [], ['timeout' => TemporalGrpcTimeouts::LONG_POLL_US]);
            $resp = GrpcUnary::wait($call);
            if ($resp instanceof PollWorkflowTaskQueueResponse && '' !== $resp->getTaskToken()) {
                return $resp;
            }
            usleep(50_000);
        }

        throw new \RuntimeException('Workflow task poll timed out.');
    }

    private function buildScheduleActivityCommand(): Command
    {
        $stc = new Duration();
        $stc->setSeconds(30);

        $attrs = new ScheduleActivityTaskCommandAttributes();
        $attrs->setActivityId('spike-activity-1');
        $attrs->setActivityType(new ActivityType(['name' => self::ACTIVITY_TYPE]));
        $attrs->setTaskQueue(new TaskQueue(['name' => self::ACTIVITY_TASK_QUEUE]));
        $attrs->setStartToCloseTimeout($stc);

        $cmd = new Command();
        $cmd->setCommandType(CommandType::COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK);
        $cmd->setScheduleActivityTaskCommandAttributes($attrs);

        return $cmd;
    }

}
