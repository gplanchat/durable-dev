<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Event\NexusOperationCancelled;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationFailed;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Event\NexusOperationTimedOut;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * The debug toolbar panel shows a run's Nexus operations (#670): where each one is served, and
 * whether it is still in flight or settled — an operation in flight is a wait served by someone
 * else, and an operator who cannot see it looks for the fault in the wrong system.
 */
final class TheProfilerShowsNexusOperationsTest extends TestCase
{
    public function testAnOperationWithNoOutcomeIsShownInFlight(): void
    {
        $events = new InMemoryEventStore();
        $events->append(new NexusOperationScheduled(ExecutionId::fromString('exec-1'), 5, 'demo-shop-stock', 'stock', 'reserve'));

        $collector = $this->collect($events);

        self::assertSame(
            [['endpoint' => 'demo-shop-stock', 'service' => 'stock', 'operation' => 'reserve', 'state' => 'in_flight', 'stateLabel' => 'in flight']],
            $collector->getExecutionsDetail()[0]['nexusOperations'],
        );
        $panel = $this->renderPanel($collector);
        foreach (['Nexus operations', 'demo-shop-stock', '<td>stock</td>', '<td>reserve</td>', 'in flight'] as $shown) {
            self::assertStringContainsString($shown, $panel);
        }
    }

    public function testEachOutcomeIsShownAsItsOwnState(): void
    {
        $events = new InMemoryEventStore();
        $outcomes = [
            'completed' => NexusOperationCompleted::class,
            'failed' => NexusOperationFailed::class,
            'timed out' => NexusOperationTimedOut::class,
            'cancelled' => NexusOperationCancelled::class,
        ];
        $id = 0;
        foreach ($outcomes as $label => $outcome) {
            $events->append(new NexusOperationScheduled(ExecutionId::fromString('exec-1'), ++$id, 'demo-business-billing', 'billing', 'op-' . $id));
            $events->append(new $outcome(ExecutionId::fromString('exec-1'), $id));
        }

        $collector = $this->collect($events);

        self::assertSame(array_keys($outcomes), array_column($collector->getExecutionsDetail()[0]['nexusOperations'], 'stateLabel'));
        $panel = $this->renderPanel($collector);
        foreach (['completed' => 'completed', 'failed' => 'failed', 'timed out' => 'timed_out', 'cancelled' => 'cancelled'] as $label => $state) {
            // The badge itself: "failed" alone appears elsewhere in the panel.
            self::assertStringContainsString("durable-nexus-state--{$state}\">{$label}<", $panel);
        }
        self::assertStringNotContainsString('in flight', $panel);
    }

    private function collect(InMemoryEventStore $events): DurableDataCollector
    {
        $collector = new DurableDataCollector(new DurableExecutionTrace(), new InMemoryWorkflowMetadataStore(), $events);
        $collector->collect(new Request(['durable_execution' => 'exec-1']), new Response());

        return $collector;
    }

    /**
     * The panel block of the real template; the WebProfiler layout it extends is stubbed, since the
     * profiler bundle is the application's, not a dependency of the bundle.
     */
    private function renderPanel(DurableDataCollector $collector): string
    {
        $views = new FilesystemLoader();
        $views->addPath(\dirname(__DIR__, 3) . '/src/DurableBundle/Resources/views', 'Durable');
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['@WebProfiler/Profiler/layout.html.twig' => '{% block head %}{% endblock %}{% block panel %}{% endblock %}']),
            $views,
        ]), ['strict_variables' => true]);

        return $twig->load('@Durable/Collector/durable.html.twig')->renderBlock('panel', ['collector' => $collector]);
    }
}
