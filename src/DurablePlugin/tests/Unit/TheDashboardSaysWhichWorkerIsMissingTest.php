<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Store\TemporalTaskQueueProbe;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Bundle\Observation\WorkerPresence;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Plugin\Controller\AdminDashboardController;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Temporal\Api\Enums\V1\TaskQueueType;
use Temporal\Api\Taskqueue\V1\PollerInfo;
use Temporal\Api\Workflowservice\V1\DescribeTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\DescribeTaskQueueResponse;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The list page hands the template one row per `durable:worker` role, from the bundle's
 * WorkerPresence, and nothing when the backend does not answer or has no such roles.
 */
final class TheDashboardSaysWhichWorkerIsMissingTest extends TestCase
{
    public function testEachRoleIsARowThatSaysWhetherItsWorkerPolls(): void
    {
        $rows = $this->workers(new WorkerPresence($this->probe([TaskQueueType::TASK_QUEUE_TYPE_WORKFLOW => time()]), ['workflow', 'activity']));

        self::assertSame([
            ['role' => 'workflow', 'pollers' => 1, 'polling' => true, 'error' => null, 'seconds' => 120],
            ['role' => 'activity', 'pollers' => 0, 'polling' => false, 'error' => null, 'seconds' => 120],
        ], $rows);
    }

    public function testWithoutWorkerPresenceThereIsNoRow(): void
    {
        self::assertSame([], $this->workers(null));
    }

    public function testABackendThatDoesNotAnswerIsNotAskedWhoPolls(): void
    {
        self::assertSame([], $this->workers(new WorkerPresence($this->probe([]), ['workflow']), reachable: false));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workers(?WorkerPresence $presence, bool $reachable = true): array
    {
        $twig = new Environment(new ArrayLoader([
            '@SyliusAdmin/shared/layout/base.html.twig' => '',
            '@DurablePlugin/admin/dashboard/index.html.twig' => '{{ workers|json_encode|raw }}',
        ]));
        $catalog = new class ($reachable) implements WorkflowRunCatalogInterface {
            public function __construct(private readonly bool $reachable) {}

            public function canFilterRuns(?WorkflowRunFilter $filter = null): bool
            {
                return true;
            }

            public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20, ?WorkflowRunFilter $filter = null): WorkflowRunPage
            {
                return new WorkflowRunPage([], null);
            }

            public function findRun(ExecutionId $executionId): ?WorkflowRunDescription
            {
                return null;
            }

            public function readHistory(WorkflowRunDescription $run): array
            {
                return [];
            }

            public function checkHealth(): BackendHealth
            {
                return new BackendHealth('Temporal', $this->reachable, 'checked', new \DateTimeImmutable('@1700000000'));
            }
        };

        $page = (new AdminDashboardController($twig, workers: $presence))->index(Request::create('/durable/runs'), new RunDashboard($catalog));
        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) $page->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $rows;
    }

    /**
     * @param array<int, int> $lastPolls task queue type => unix time of its one poller's last poll
     */
    private function probe(array $lastPolls): TemporalTaskQueueProbe
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('DescribeTaskQueue')->willReturnCallback(static function (DescribeTaskQueueRequest $request) use ($lastPolls): DescribeTaskQueueResponse {
            $response = new DescribeTaskQueueResponse();
            $at = $lastPolls[$request->getTaskQueueType()] ?? null;
            if (null !== $at) {
                $response->setPollers([new PollerInfo(['last_access_time' => new Timestamp(['seconds' => $at])])]);
            }

            return $response;
        });

        return new TemporalTaskQueueProbe($client, new TemporalConnection('localhost:7233', 'default'));
    }
}
