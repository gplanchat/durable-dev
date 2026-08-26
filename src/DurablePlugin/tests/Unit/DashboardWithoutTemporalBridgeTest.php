<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Gplanchat\Durable\Plugin\Dashboard\TemporalEventsDashboardDataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Temporal bridge requires ext-grpc, which most Sylius hosts do not ship. It is therefore a
 * `suggest`, not a `require`: the dashboard has to render without it rather than make the whole
 * plugin uninstallable. This pins the degraded path — no Temporal class is reached when the
 * container wires nothing (`nullOnInvalid()` in Resources/config/services.php).
 */
final class DashboardWithoutTemporalBridgeTest extends TestCase
{
    public function testRunsPageDegradesInsteadOfFailing(): void
    {
        $page = (new TemporalEventsDashboardDataProvider())->provideRunsPage();

        self::assertSame([], $page['runs']);
        self::assertNull($page['nextCursor']);
        self::assertFalse($page['temporal']['connected']);
        self::assertNull($page['temporal']['namespace']);
        self::assertNotSame('', $page['temporal']['message']);
    }

    public function testHistoryEnrichmentIsSkippedInsteadOfFailing(): void
    {
        $run = [
            'runId' => 'run-1',
            'workflowId' => 'wf-1',
            'workflowName' => 'OrderWorkflow',
            'status' => 'running',
            'taskQueue' => 'durable-workflows',
            'startedAt' => '12:00:00.000',
            'duration' => '1s',
            'events' => [],
        ];

        self::assertSame($run, (new TemporalEventsDashboardDataProvider())->enrichWithHistory($run));
    }
}
