<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\WorkflowStartOptions;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;

/**
 * Un cron Temporal n'est pas un planificateur externe : c'est la même exécution logique, que le
 * serveur relance à chaque échéance avec un historique neuf.
 */
final class CronScheduleTest extends TemporalServerTestCase
{
    /** Expressions dont le verdict serveur a été sondé, et que le validateur local doit rendre. */
    private const PROBED_EXPRESSIONS = [
        '0 9 * * 1-5' => true,
        '*/15 * * * *' => true,
        '@daily' => true,
        '@every 90s' => true,
        'CRON_TZ=Europe/Paris 0 9 * * 1-5' => true,
        '0 0 * * ?' => true,
        '0 0 29 2 *' => true,
        '0 0 31 4 *' => false,
        '70 * * * *' => false,
        '* * * * * *' => false,
        '@bogus' => false,
        '0 0 * * 7' => false,
    ];

    public function testCronScheduleIsCarriedByTheStartRequest(): void
    {
        $executionId = 'cron-' . bin2hex(random_bytes(4));
        $this->workflowClient()->startCron('Ticking', ['value' => 5], $executionId, '@every 2s');

        $started = $this->waitForHistoryEvent($executionId, EventType::EVENT_TYPE_WORKFLOW_EXECUTION_STARTED);
        $attrs = $started->getWorkflowExecutionStartedEventAttributes();

        self::assertNotNull($attrs);
        self::assertSame('@every 2s', $attrs->getCronSchedule());
    }

    public function testTheServerRelaunchesTheWorkflowAfterEachRun(): void
    {
        $executionId = 'cron-' . bin2hex(random_bytes(4));
        $this->workflowClient()->startCron('Ticking', ['value' => 5], $executionId, '@every 2s');

        // Le premier run doit se terminer, puis le serveur en enchaîne un second : deux runs
        // distincts sous le même workflowId.
        $runIds = $this->distinctRunIdsWithin($executionId, 2, 60.0);

        self::assertCount(2, $runIds, 'le serveur doit relancer une seconde exécution');
    }

    public function testTheLocalValidatorAgreesWithTheServer(): void
    {
        // Le validateur de CronSchedule a été calibré expression par expression contre ce
        // serveur. Ce test empêche les deux de diverger : c'est le serveur qui fait autorité.
        $disagreements = [];

        foreach (self::PROBED_EXPRESSIONS as $expression => $serverAccepts) {
            try {
                CronSchedule::parse($expression);
                $locallyAccepts = true;
            } catch (\InvalidArgumentException) {
                $locallyAccepts = false;
            }

            if ($locallyAccepts !== $this->serverAccepts($expression)) {
                $disagreements[] = \sprintf('%s (local=%s, serveur=%s)', $expression, $locallyAccepts ? 'OK' : 'rejet', $this->serverAccepts($expression) ? 'OK' : 'rejet');
            }
            self::assertSame($serverAccepts, $locallyAccepts, \sprintf('verdict attendu pour "%s"', $expression));
        }

        self::assertSame([], $disagreements);
    }

    public function testCronOnAChildWorkflowReachesTheStartCommand(): void
    {
        // Le cron enfant passe par les schedulingMetadata ; on vérifie la traduction, sans
        // attendre une seconde occurrence côté serveur.
        $options = new ChildWorkflowOptions(cronSchedule: CronSchedule::parse('0 * * * *'));

        self::assertSame('0 * * * *', $options->toSchedulingMetadata()['cron_schedule'] ?? null);
        self::assertSame('@every 5m', WorkflowStartOptions::cron('@every 5m')->toStartMetadata()['cron_schedule'] ?? null);
    }

    /**
     * Le serveur accepte-t-il cette expression ? Une tentative de démarrage réelle.
     */
    private function serverAccepts(string $expression): bool
    {
        $req = new \Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest();
        $req->setNamespace($this->connection->namespace->name());
        $req->setWorkflowId('cron-agreement-' . bin2hex(random_bytes(5)));
        $req->setWorkflowType(new \Temporal\Api\Common\V1\WorkflowType(['name' => 'Ticking']));
        $req->setTaskQueue(new \Temporal\Api\Taskqueue\V1\TaskQueue(['name' => $this->connection->workflowTaskQueue . '-unserved']));
        $req->setIdentity('cron-agreement');
        $req->setCronSchedule($expression);

        [, $status] = $this->client->StartWorkflowExecution($req, [], ['timeout' => 10_000_000])->wait();

        return 0 === (int) ($status->code ?? -1);
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
        $req->setNamespace($this->connection->namespace->name());
        $req->setExecution(new WorkflowExecution(['workflow_id' => $workflowId]));

        [$response, $status] = $this->client->DescribeWorkflowExecution($req, [], ['timeout' => 10_000_000])->wait();
        if (0 !== (int) ($status->code ?? -1) || !$response instanceof \Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse) {
            return null;
        }

        $runId = $response->getWorkflowExecutionInfo()?->getExecution()?->getRunId();

        return \is_string($runId) && '' !== $runId ? $runId : null;
    }
}
