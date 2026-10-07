<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * The Executions tab draws the shared `RunTimeline` of each collected run (#819): one line per
 * action, the time spent waiting for a worker hatched, red only on the interval that leads into a
 * failure. A run that waited for a worker used to look like any other in the profiler.
 */
final class TheProfilerDrawsTheSharedRunTimelineTest extends TestCase
{
    public function testAQueuedActivityIsHatchedAndOnlyTheFailureIsRed(): void
    {
        $collector = $this->collectFailedRun();

        $panel = new \DOMDocument();
        libxml_use_internal_errors(true);
        $panel->loadHTML('<html><body>' . $this->renderPanel($collector) . '</body></html>');
        libxml_clear_errors();
        $xpath = new \DOMXPath($panel);

        $rows = $xpath->query('//div[contains(@class, "durable-frieze-row")]');
        self::assertNotFalse($rows);
        $drawn = [];
        foreach ($rows as $row) {
            $bars = $xpath->query('.//span[contains(@class, "durable-frieze-bar")]', $row);
            self::assertNotFalse($bars);
            $drawn[trim($row->textContent)] = array_map(static fn(\DOMNode $bar): string => $bar instanceof \DOMElement ? $bar->getAttribute('class') : '', iterator_to_array($bars));
        }

        // The run first, then the activity: its wait for a worker hatched, the attempt that failed red.
        self::assertSame(
            [
                ['durable-frieze-bar execution failed'],
                ['durable-frieze-bar activity waiting', 'durable-frieze-bar activity failed'],
            ],
            array_values($drawn),
        );
        self::assertStringContainsString('charge', array_keys($drawn)[1]);
        // A key names the hatching and the red, as on the run pages.
        self::assertSame(2, self::nodesMatching($xpath, '//div[contains(@class, "durable-frieze-key")]'));
    }

    public function testTheTimelineKeepsNoUnmaskedPayloadInTheProfile(): void
    {
        self::assertStringNotContainsString('s3cret', serialize($this->collectFailedRun()));
    }

    private function collectFailedRun(): DurableDataCollector
    {
        $clock = new class implements ClockInterface {
            private int $tick = 0;

            public function now(): \DateTimeImmutable
            {
                // One event every two seconds: the activity waits for a worker, then fails.
                return new \DateTimeImmutable('@' . (1_790_000_000 + 2 * $this->tick++));
            }
        };
        $id = ExecutionId::fromString('exec-1');
        $events = new InMemoryEventStore($clock);
        $events->append(new ExecutionStarted($id, ['order' => 7]));
        $events->append(new ActivityScheduled($id, 'act-1', 'charge', ['password' => 's3cret']));
        $events->append(new ActivityTaskStarted($id, 'act-1', 'charge', 1));
        $events->append(new ActivityFailed($id, 'act-1', \RuntimeException::class, 'card declined'));
        $events->append(WorkflowExecutionFailed::workflowHandlerFailure($id, new \RuntimeException('card declined')));

        $collector = new DurableDataCollector(new DurableExecutionTrace(), new InMemoryWorkflowMetadataStore(), $events);
        $collector->collect(new Request(['durable_execution' => 'exec-1']), new Response());

        return $collector;
    }

    private static function nodesMatching(\DOMXPath $xpath, string $expression): int
    {
        $nodes = $xpath->query($expression);
        self::assertNotFalse($nodes);

        return $nodes->length;
    }

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
