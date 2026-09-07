<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Integration;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The template rendered for real, on a run that has a history.
 *
 * The other assertions in this directory read the file; this one executes it. The difference is
 * not cosmetic: the frieze now comes from the core, and the template walks `action.events`, then
 * `mark.event.label` — a path no text read puts to the test. A misnamed property in that chain
 * breaks nothing at install time and renders an empty page in production, on precisely the screen
 * an operator came to look at.
 */
final class TheDashboardRendersARunHistoryTest extends TestCase
{
    public function testTheHistoryOfTheSelectedRunReachesThePage(): void
    {
        $page = $this->render();

        self::assertStringContainsString('SendWelcomeEmail', $page);
        self::assertStringContainsString('orderApproved', $page, 'a signal is a row of its own');
        self::assertStringContainsString('#1', $page, 'every event keeps its rank');
    }

    public function testAnEventCarryingSomethingUnfoldsAndAnEmptyOneStaysALine(): void
    {
        // An expander that opens on nothing gets reopened every time: that is exactly what we do
        // not want to make someone hunting for a failure do twice.
        $page = $this->render();

        self::assertStringContainsString('<details>', $page);
        self::assertStringContainsString('cus-42', $page);
        self::assertSame(1, substr_count($page, '<details>'), 'only one of the two events has anything to unfold');
    }

    public function testAnEphemeralJournalIsNeitherAFailureNorASuccess(): void
    {
        // The third state: it answers, and its answer is empty by construction.
        $page = $this->render(ephemeral: true);

        self::assertStringContainsString('alert-info', $page);
        self::assertStringNotContainsString('alert-warning', $page);
        self::assertStringNotContainsString('alert-success', $page);
    }

    public function testTheActionsArePlacedInTimeAndNotMerelyStacked(): void
    {
        // Stacking blocks answers "in what order", never "for how long" — and the second is the
        // question an operator in front of a slow run comes to ask.
        $page = $this->render();

        self::assertStringContainsString('durable-frieze', $page);
        // The activity opens at 0 s and the signal falls at 20 s over a 20 s span: the signal's
        // mark is therefore all the way right. A spread by rank would have put it in the middle.
        self::assertMatchesRegularExpression('/left: 100\.000%/', $page);
    }

    public function testWaitingToBePickedUpIsHatchedAndSaysSoOnHover(): void
    {
        $page = $this->render();

        self::assertStringContainsString('waiting', $page);
        self::assertStringContainsString('waiting to be picked up', $page, 'hatching with no legend is a guessing game');
    }

    public function testTheHatchingIsExplainedOnThePageAndNotOnlyOnHover(): void
    {
        // Hovering assumes you know there is something to hover over.
        $page = $this->render();

        self::assertStringContainsString('durable-frieze-key', $page);
    }

    public function testAPayloadWithABadByteStillUnfoldsOnWhatIsReadable(): void
    {
        // Without tolerance, `json_encode` returned `false`: the expander opened on nothing, and
        // this is the screen an operator opens as a last resort.
        $page = $this->render(badPayload: true);

        self::assertStringContainsString('<details>', $page);
        self::assertStringContainsString('ORD-7', $page);
    }

    public function testAnEventReadsAtTheSameMomentInTheFriezeAndInTheList(): void
    {
        // The frieze composes its tooltip in the core, with the event's time zone; Twig's `date`
        // filter, for its part, applies the server's. On a machine in Paris, the same event read
        // 22:13:20 on hover and 23:13:20 in the row just below it — in a page whose whole reason
        // for being is that an operator has nothing to convert in their head.
        $was = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $page = $this->render();
        } finally {
            date_default_timezone_set($was);
        }

        preg_match_all('/(\d{2}:\d{2}:\d{2}\.\d{3})/', $page, $found);
        $readings = array_values(array_unique($found[1]));
        sort($readings);

        self::assertSame(['22:13:20.000', '22:13:30.000', '22:13:40.000'], $readings);
    }

    public function testTheCountersNameTheirScopeRatherThanClaimingATotal(): void
    {
        // A "Total" heading with twenty under it teaches the operator that an application which
        // recorded five hundred runs has twenty. What these counters cover is the page, because
        // that is what the catalog was asked to return.
        $page = $this->render();

        self::assertStringContainsString('runs on this page', $page);
        self::assertStringContainsString('On this page', $page);
        self::assertStringNotContainsString('>Total<', $page);
    }

    private function render(bool $ephemeral = false, bool $badPayload = false): string
    {
        $catalog = new RenderingCatalog($ephemeral, $badPayload);
        $model = (new RunDashboard($catalog))->build();

        return $this->twig()->render('@DurablePlugin/admin/dashboard/index.html.twig', $model);
    }

    private function twig(): Environment
    {
        $plugin = new FilesystemLoader([\dirname(__DIR__, 2) . '/Resources/views'], null);
        $plugin->addPath(\dirname(__DIR__, 2) . '/Resources/views', 'DurablePlugin');

        // The Sylius admin chrome is not installed here, and does not have to be: this test guards
        // the page, not the store.
        $sylius = new ArrayLoader([
            '@SyliusAdmin/shared/layout/base.html.twig' => '{% block title %}{% endblock %}{% block stylesheets %}{% endblock %}{% block body %}{% endblock %}',
            '@SyliusAdmin/shared/crud/common/sidebar.html.twig' => '',
            '@SyliusAdmin/shared/crud/common/navbar.html.twig' => '',
        ]);

        $twig = new Environment(new ChainLoader([$plugin, $sylius]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn(string $route, array $parameters = []): string => '/admin/durable/dashboard'));

        return $twig;
    }
}

final class RenderingCatalog implements WorkflowRunCatalogInterface
{
    public function __construct(
        private readonly bool $ephemeral = false,
        private readonly bool $badPayload = false,
    ) {}

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        if ($this->ephemeral) {
            return new WorkflowRunPage([]);
        }

        return new WorkflowRunPage([
            new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, new \DateTimeImmutable('@1700000000')),
        ]);
    }

    public function readHistory(WorkflowRunDescription $run): array
    {
        return [
            new WorkflowRunEvent(
                1,
                new \DateTimeImmutable('@1700000000'),
                WorkflowRunEventKind::Activity,
                'SendWelcomeEmail',
                $this->badPayload
                    ? ['orderId' => 'ORD-7', 'blob' => "\xB1\x31"]
                    : ['payload' => ['customerId' => 'cus-42']],
                'activity:act-1',
            ),
            // Picked up ten seconds after being scheduled: the first ten seconds are a queue, not
            // work.
            new WorkflowRunEvent(
                2,
                new \DateTimeImmutable('@1700000010'),
                WorkflowRunEventKind::Activity,
                'SendWelcomeEmail',
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
        ];
    }

    public function checkHealth(): BackendHealth
    {
        return new BackendHealth(
            'Fake backend',
            true,
            'The fake backend answers.',
            new \DateTimeImmutable('@1700000000'),
            $this->ephemeral,
        );
    }
}
