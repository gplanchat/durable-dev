<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;

/**
 * The detail template, rendered for real.
 *
 * ⚠ **Nothing was checking it, and nothing could check it.** PHPStan and Psalm run against the real
 * Magento classes in CI, but neither of the two analyses a `.phtml`. This template has just been
 * rewritten onto an object API — `$action->durationLabel`, `$row->actionLabel`,
 * `$row->renderedDetails` — where it used to read arrays: a mis-named property breaks nothing there
 * at installation and renders an empty screen, on the very one an operator came to look at.
 *
 * The block, for its part, is typed and CI sees it. It is the template that had no net; it has one
 * now, and it needs neither Magento nor a database — a double that answers the methods called is
 * enough, and that is what makes this test bearable inside the ordinary suite.
 */
final class TheDetailTemplateRendersARunHistoryTest extends TestCase
{
    public function testTheFriezePlacesTheActionsAndHatchesTheWait(): void
    {
        $page = $this->renderDetail();

        self::assertStringContainsString('durable-frieze', $page);
        // The pick-up falls at 10 s over a 20 s span: the waiting bar takes up the first half of
        // the track. A spread by rank would have put it somewhere else.
        self::assertStringContainsString('left: 0.000%; width: 50.000%', $page);
        self::assertStringContainsString('waiting', $page);
        self::assertStringContainsString('waiting to be picked up', $page);
    }

    public function testEveryJournalRowNamesItsActionAndNotItsEvent(): void
    {
        // `ActivityTaskStarted` names the event class; the operator is looking for `charge`. The
        // column therefore carries the action name, and it is the same string as on the frieze row.
        $page = $this->renderDetail();

        // The "Action" column of the activity's two rows, scheduling and start.
        self::assertSame(2, substr_count($page, '<td>charge</td>'));
        self::assertStringContainsString('ActivityTaskStarted', $page, 'the row also keeps its own label');
        // And the signal, which is its own action all by itself, names itself rather than leaving
        // a hole in the column.
        self::assertSame(1, substr_count($page, '<td>orderApproved</td>'));
    }

    public function testAnEventWithNothingRecordedHasNoExpander(): void
    {
        // An expander that opens onto nothing gets reopened every time.
        $page = $this->renderDetail();

        self::assertSame(1, substr_count($page, '<details>'));
        self::assertStringContainsString('ORD-7', $page);
    }

    public function testAnUnknownRunSaysSoRatherThanRenderingAnEmptyScreen(): void
    {
        $page = $this->renderDetail(known: false);

        self::assertStringContainsString('No execution named', $page);
        self::assertStringNotContainsString('durable-frieze', $page);
    }

    private function renderDetail(bool $known = true): string
    {
        require_once __DIR__ . '/Fixture/magento-template-globals.php';

        $block = new DetailBlockDouble($known);
        $escaper = new EscaperDouble();

        ob_start();

        try {
            require __DIR__ . '/../../../src/DurableModule/view/adminhtml/templates/process/detail.phtml';
        } finally {
            // `finally` and not the rest of the flow: an error in the template would otherwise
            // leave the buffer open, and PHPUnit then reports a "risky" test on top of the real
            // failure — two messages for one cause, of which the one that counts is not the first.
            $page = ob_get_clean();
        }

        self::assertIsString($page);

        return $page;
    }
}

/**
 * What the template calls on its block, and nothing more. The real block extends `Template`,
 * which demands Magento's container — absent from this suite, and it has no business being there.
 */
final class DetailBlockDouble
{
    private readonly RunTimeline $timeline;

    public function __construct(
        private readonly bool $known = true,
    ) {
        $this->timeline = RunTimeline::of($known ? [
            new WorkflowRunEvent(
                1,
                new \DateTimeImmutable('@1700000000'),
                WorkflowRunEventKind::Activity,
                'charge',
                ['orderId' => 'ORD-7'],
                'activity:act-1',
            ),
            // Picked up ten seconds later: the first ten seconds are a queue.
            new WorkflowRunEvent(
                2,
                new \DateTimeImmutable('@1700000010'),
                WorkflowRunEventKind::Activity,
                'ActivityTaskStarted',
                [],
                'activity:act-1',
                started: true,
            ),
            new WorkflowRunEvent(
                3,
                new \DateTimeImmutable('@1700000020'),
                WorkflowRunEventKind::Signal,
                'orderApproved',
            ),
        ] : []);
    }

    public function getRunId(): string
    {
        return 'run-1';
    }

    public function getRun(): ?WorkflowRunDescription
    {
        return $this->known
            ? new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, new \DateTimeImmutable('@1700000000'))
            : null;
    }

    public function getTimeline(): RunTimeline
    {
        return $this->timeline;
    }

    public function scale(float $seconds): string
    {
        $span = $this->timeline->span;

        return number_format($span > 0.0 ? $seconds / $span * 100.0 : 0.0, 3, '.', '');
    }

    public function formatMoment(?\DateTimeImmutable $moment): string
    {
        return $moment === null ? '—' : $moment->format('Y-m-d H:i:s');
    }

    public function getBackUrl(): string
    {
        return '/admin/durable/process/history';
    }
}

/**
 * Magento's escaping contract, reduced to what this template calls.
 */
final class EscaperDouble
{
    public function escapeHtml(mixed $value): string
    {
        return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    public function escapeHtmlAttr(mixed $value): string
    {
        return $this->escapeHtml($value);
    }

    public function escapeUrl(string $value): string
    {
        return $this->escapeHtml($value);
    }
}
