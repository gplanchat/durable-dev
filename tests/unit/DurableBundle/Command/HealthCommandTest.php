<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Command;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Store\TemporalTaskQueueProbe;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Bundle\Command\HealthCommand;
use Gplanchat\Durable\Bundle\Observation\WorkerPresence;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Temporal\Api\Enums\V1\TaskQueueType;
use Temporal\Api\Taskqueue\V1\PollerInfo;
use Temporal\Api\Workflowservice\V1\DescribeTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\DescribeTaskQueueResponse;

/**
 * Without an activity worker an execution stops at its first activity, and nothing fails: this exit
 * code is what a supervisor alerts on instead.
 */
final class HealthCommandTest extends TestCase
{
    public function testEveryRoleThatPollsIsHealthy(): void
    {
        $tester = $this->probe(['workflow', 'activity'], [TaskQueueType::TASK_QUEUE_TYPE_WORKFLOW => time(), TaskQueueType::TASK_QUEUE_TYPE_ACTIVITY => time()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('poller(s) on durable-workflows', $tester->getDisplay());
        self::assertStringContainsString('poller(s) on durable-activities', $tester->getDisplay());
    }

    public function testAStoppedRoleFailsAndSaysWhatToStart(): void
    {
        $tester = $this->probe(['workflow', 'activity'], [TaskQueueType::TASK_QUEUE_TYPE_WORKFLOW => time(), TaskQueueType::TASK_QUEUE_TYPE_ACTIVITY => time() - 300]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('durable:worker --role=activity', $tester->getErrorOutput(), 'an alert script reads stderr');
        self::assertStringNotContainsString('--role=workflow', $tester->getDisplay() . $tester->getErrorOutput());
    }

    public function testNexusIsCheckedOnlyWhenTheApplicationServesIt(): void
    {
        $tester = $this->probe(['workflow', 'activity', 'nexus'], [TaskQueueType::TASK_QUEUE_TYPE_WORKFLOW => time(), TaskQueueType::TASK_QUEUE_TYPE_ACTIVITY => time()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('durable:worker --role=nexus', $tester->getErrorOutput());
    }

    public function testAnUnansweredProbeBlamesNoWorker(): void
    {
        $tester = $this->probe(['workflow'], [], failing: true);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('could not ask', $tester->getErrorOutput());
        self::assertStringNotContainsString('--role=', $tester->getDisplay() . $tester->getErrorOutput());
    }

    public function testNoRoleOnTheClusterHasNothingToCheck(): void
    {
        $tester = $this->probe([], []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * @param list<string>    $roles
     * @param array<int, int> $lastPolls task queue type => unix time of its one poller's last poll
     */
    private function probe(array $roles, array $lastPolls, bool $failing = false): CommandTester
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('DescribeTaskQueue')->willReturnCallback(static function (DescribeTaskQueueRequest $request) use ($lastPolls, $failing): DescribeTaskQueueResponse {
            if ($failing) {
                throw new \RuntimeException('connection refused');
            }
            $response = new DescribeTaskQueueResponse();
            $at = $lastPolls[$request->getTaskQueueType()] ?? null;
            if (null !== $at) {
                $response->setPollers([new PollerInfo(['last_access_time' => new Timestamp(['seconds' => $at])])]);
            }

            return $response;
        });

        $tester = new CommandTester(new HealthCommand(new WorkerPresence(new TemporalTaskQueueProbe($client, new TemporalConnection('localhost:7233', 'default')), $roles)));
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }
}
