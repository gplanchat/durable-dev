<?php

declare(strict_types=1);

namespace App\Tests\Integration\Dashboard;

use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class DashboardTemplateRenderTest extends KernelTestCase
{
    public function testDashboardTemplateRendersRunningTimelapseBarClass(): void
    {
        self::assertStringContainsString(
            'ds-timelapse__bar ds-timelapse__bar--execution ds-timelapse__bar--running',
            $this->render(),
        );
    }

    public function testASuspendedRunSaysWhatItWaitsOn(): void
    {
        // #324: the catalogue records the wait and RunDashboard words it; the list only showed
        // the wait for a worker.
        self::assertStringContainsString('waiting on signal approve', $this->render(['waitingOn' => 'waiting on signal approve']));
    }

    /**
     * @param array<string, string> $run what the run row carries beyond the base fields
     */
    private function render(array $run = []): string
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        \assert($twig instanceof Environment);
        $requestStack = self::getContainer()->get(RequestStack::class);
        \assert($requestStack instanceof RequestStack);
        $requestStack->push(new Request());

        return $twig->render('dashboard/index.html.twig', [
            'backend' => ['available' => true, 'ephemeral' => false, 'message' => ''],
            'statuses' => WorkflowRunStatus::cases(),
            'runs' => [$run + [
                'runId' => 'run-1',
                'workflowName' => 'DemoWorkflow',
                'status' => 'running',
                'startedAt' => '10:00:00.000',
                'taskQueue' => 'default',
                'duration' => '20s',
            ]],
            'selectedRun' => [
                'runId' => 'run-1',
                'workflowName' => 'DemoWorkflow',
                'status' => 'running',
                'startedAt' => '10:00:00.000',
                'taskQueue' => 'default',
                'duration' => '20s',
                'events' => [],
                'timeline' => [
                    'startTime' => '10:00:00.000',
                    'endTime' => '10:00:20.000',
                    'windowDurationLabel' => '20s',
                    'lanes' => [[
                        'label' => 'Execution',
                        'kind' => 'execution',
                        'startPercent' => 0.0,
                        'widthPercent' => 100.0,
                        'startTime' => '10:00:00.000',
                        'endTime' => '10:00:20.000',
                        'isRunning' => true,
                    ]],
                ],
            ],
            'selectedRunId' => 'run-1',
            'query' => '',
            'status' => 'all',
            'animateTimelapse' => true,
            'kpis' => [
                'total' => 1,
                'running' => 1,
                'completed' => 0,
                'failed' => 0,
            ],
            'timelineControls' => [
                'visibleKinds' => ['execution', 'activity', 'signal', 'query', 'update'],
                'availableKinds' => ['execution', 'activity', 'signal', 'query', 'update'],
            ],
            'pagination' => [
                'hasPrevious' => false,
                'previousCursor' => null,
                'previousStack' => '',
                'hasNext' => false,
                'nextCursor' => null,
                'nextStack' => '',
                'cursor' => '',
                'stack' => '',
                'cursorHint' => 'START',
                'nextCursorHint' => null,
                'pageSize' => 20,
            ],
        ]);
    }
}
