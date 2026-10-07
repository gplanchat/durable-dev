<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Event\ActivityScheduled;
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
 * The panel folds each event payload behind a closed disclosure, as the run pages do (#820): on a
 * run of hundreds of events, an open payload per row makes the page long, and puts masked values in
 * front of an operator who did not ask for them.
 */
final class TheProfilerFoldsEventPayloadsTest extends TestCase
{
    public function testEachEventPayloadSitsInAClosedDisclosure(): void
    {
        $events = new InMemoryEventStore();
        $events->append(new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-1', 'charge', ['amount' => 42, 'password' => 's3cret']));
        $trace = new DurableExecutionTrace();
        $trace->onWorkflowDispatchRequested(ExecutionId::fromString('exec-1'), 'Order', ['order' => 7], false, 'async');

        $collector = new DurableDataCollector($trace, new InMemoryWorkflowMetadataStore(), $events);
        $collector->collect(new Request(), new Response());

        $panel = new \DOMDocument();
        libxml_use_internal_errors(true);
        $panel->loadHTML('<html><body>' . $this->renderPanel($collector) . '</body></html>');
        libxml_clear_errors();
        $xpath = new \DOMXPath($panel);

        $folded = $xpath->query('//details/pre');
        self::assertNotFalse($folded);
        $payloads = array_map(static fn(\DOMNode $pre): string => $pre->textContent, iterator_to_array($folded));
        self::assertCount(2, $payloads, 'the journal event and the dispatch each fold their payload');
        self::assertStringContainsString('"amount": 42', implode("\n", $payloads));
        self::assertStringContainsString('"order": 7', implode("\n", $payloads));
        // The masking stays: the redactor runs before the payload reaches the template.
        self::assertStringNotContainsString('s3cret', implode("\n", $payloads));

        self::assertSame(0, self::nodesMatching($xpath, '//details[@open]'));
        self::assertSame(0, self::nodesMatching($xpath, '//td/pre[not(ancestor::details)]'), 'no event payload is left open');
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
