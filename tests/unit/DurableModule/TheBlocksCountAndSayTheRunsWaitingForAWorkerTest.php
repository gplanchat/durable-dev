<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\DurableModule\Block\Adminhtml\ProcessDetail;
use Gplanchat\DurableModule\Block\Adminhtml\ProcessHistory;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Ui\WaitingForWorker;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture/magento-block-template.php';
require_once __DIR__ . '/Fixture/magento-template-globals.php';

/**
 * #818: the real block methods, not a double of them, since the filter on `waitingForWorkerSince`
 * and the `tellsWaitingForWorker` gate live there.
 */
final class TheBlocksCountAndSayTheRunsWaitingForAWorkerTest extends TestCase
{
    public function testTheRunPageSaysHowLongTheRunHasWaited(): void
    {
        $run = new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, waitingForWorkerSince: (new \DateTimeImmutable())->modify('-5 minutes -1 second'));

        self::assertSame('waiting for a worker · 5 min', $this->detail($run)->getWaitingForWorker());
    }

    public function testTheRunPageSaysNothingOfARunAWorkerPickedUp(): void
    {
        self::assertNull($this->detail(new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running))->getWaitingForWorker());
    }

    public function testTheRunPageSaysNothingOfARunItDoesNotKnow(): void
    {
        self::assertNull($this->detail(null)->getWaitingForWorker());
    }

    public function testTheCountersCountOnlyTheRunsNoWorkerPickedUp(): void
    {
        $since = new \DateTimeImmutable('2026-10-05 10:00:00');
        $history = $this->history(new WorkflowRunPage([
            new WorkflowRunDescription('a', 'App\\OrderWorkflow', WorkflowRunStatus::Running, waitingForWorkerSince: $since),
            new WorkflowRunDescription('b', 'App\\OrderWorkflow', WorkflowRunStatus::Running),
            new WorkflowRunDescription('c', 'App\\OrderWorkflow', WorkflowRunStatus::Running, waitingForWorkerSince: $since),
        ], tellsWaitingForWorker: true));

        self::assertSame(2, $history->getWaitingForWorker());
    }

    public function testTheCountIsZeroWhenTheBackendTellsAndNoRunWaits(): void
    {
        $history = $this->history(new WorkflowRunPage([new WorkflowRunDescription('a', 'App\\OrderWorkflow', WorkflowRunStatus::Running)], tellsWaitingForWorker: true));

        self::assertSame(0, $history->getWaitingForWorker());
    }

    public function testTheCountIsLeftOutWhenTheBackendCannotTell(): void
    {
        $history = $this->history(new WorkflowRunPage([
            new WorkflowRunDescription('a', 'App\\OrderWorkflow', WorkflowRunStatus::Running, waitingForWorkerSince: new \DateTimeImmutable()),
        ], tellsWaitingForWorker: false));

        self::assertNull($history->getWaitingForWorker(), 'zero would claim that none waits');
    }

    public function testTheLineIsWordedAtTheMomentItIsGiven(): void
    {
        $since = new \DateTimeImmutable('2026-10-05 10:00:00');
        $run = new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, waitingForWorkerSince: $since);

        self::assertSame('waiting for a worker · 42 s', WaitingForWorker::of($run, $since->modify('+42 seconds')));
        self::assertNull(WaitingForWorker::of(new WorkflowRunDescription('run-2', 'App\\OrderWorkflow', WorkflowRunStatus::Running), $since));
    }

    private function detail(?WorkflowRunDescription $run): ProcessDetail
    {
        $request = new class implements RequestInterface {
            public function getParam(string $name): mixed
            {
                return 'run-1';
            }
        };
        $catalog = $this->createStub(WorkflowRunCatalogInterface::class);
        $catalog->method('findRun')->willReturn($run);
        $factory = $this->createStub(RuntimeFactory::class);
        $factory->method('catalog')->willReturn($catalog);

        return new ProcessDetail(new Context($request), $factory, $this->createStub(PayloadRedactorInterface::class));
    }

    private function history(WorkflowRunPage $page): ProcessHistory
    {
        $request = $this->createStub(RequestInterface::class);
        $catalog = $this->createStub(WorkflowRunCatalogInterface::class);
        $catalog->method('listRuns')->willReturn($page);
        $factory = $this->createStub(RuntimeFactory::class);
        $factory->method('catalog')->willReturn($catalog);

        return new ProcessHistory(new Context($request), $factory);
    }
}
