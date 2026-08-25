<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\WorkflowStartOptions;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;

/**
 * Un cron Temporal n'est pas un planificateur externe : c'est la même exécution logique, que le
 * serveur relance à chaque échéance avec un historique neuf.
 */
final class CronScheduleTest extends TemporalServerTestCase
{
    public function testCronScheduleIsCarriedByTheStartRequest(): void
    {
        $executionId = 'cron-'.bin2hex(random_bytes(4));
        $this->workflowClient()->startCron('Ticking', ['value' => 5], $executionId, '@every 2s');

        $started = $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $attrs = $started->getWorkflowExecutionStartedEventAttributes();

        self::assertNotNull($attrs);
        self::assertSame('@every 2s', $attrs->getCronSchedule());
    }

    public function testTheServerRelaunchesTheWorkflowAfterEachRun(): void
    {
        $executionId = 'cron-'.bin2hex(random_bytes(4));
        $this->workflowClient()->startCron('Ticking', ['value' => 5], $executionId, '@every 2s');

        // Le premier run doit se terminer, puis le serveur en enchaîne un second : deux runs
        // distincts sous le même workflowId.
        $runIds = $this->distinctRunIdsWithin($executionId, 2, 60.0);

        self::assertCount(2, $runIds, 'le serveur doit relancer une seconde exécution');
    }

    public function testCronOnAChildWorkflowReachesTheStartCommand(): void
    {
        // Le cron enfant passe par les schedulingMetadata ; on vérifie la traduction, sans
        // attendre une seconde occurrence côté serveur.
        $options = new ChildWorkflowOptions(cronSchedule: '0 * * * *');

        self::assertSame('0 * * * *', $options->toSchedulingMetadata()['cron_schedule'] ?? null);
        self::assertSame('@every 5m', WorkflowStartOptions::cron('@every 5m')->toStartMetadata()['cron_schedule'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function distinctRunIdsWithin(string $executionId, int $expected, float $timeoutSeconds): array
    {
        $workflowId = $this->workflowId($executionId);
        $deadline = microtime(true) + $timeoutSeconds;
        $seen = [];

        while (microtime(true) < $deadline && \count($seen) < $expected) {
            $describe = $this->describeRunId($workflowId);
            if (null !== $describe && !\in_array($describe, $seen, true)) {
                $seen[] = $describe;
            }
            usleep(300_000);
        }

        return $seen;
    }

    private function describeRunId(string $workflowId): ?string
    {
        $req = new \Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest();
        $req->setNamespace($this->connection->namespace);
        $req->setExecution(new WorkflowExecution(['workflow_id' => $workflowId]));

        [$response, $status] = $this->client->DescribeWorkflowExecution($req, [], ['timeout' => 10_000_000])->wait();
        if (0 !== (int) ($status->code ?? -1) || !$response instanceof \Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse) {
            return null;
        }

        $runId = $response->getWorkflowExecutionInfo()?->getExecution()?->getRunId();

        return \is_string($runId) && '' !== $runId ? $runId : null;
    }
}
