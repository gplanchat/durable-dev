<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Integration;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\NexusOperationState;
use Gplanchat\Durable\Observation\NexusOperationSummary;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunEventPhase;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\NexusOperationCatalogInterface;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;
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

    public function testTheRunPageMasksWhatLooksLikeASecret(): void
    {
        // #507: any admin who opens the run reads this page; the profiler already masked it (#488).
        $page = $this->render(secrets: true);

        self::assertStringContainsString('cus-42', $page);
        self::assertStringNotContainsString('hunter2', $page);
        self::assertStringNotContainsString('sk-live-123', $page);
    }

    public function testTheRunPageMasksWithTheApplicationsRedactor(): void
    {
        // An application that masks `customerId` must not see it on the run page (review of #526).
        $masksCustomers = new class implements \Gplanchat\Durable\Observation\PayloadRedactorInterface {
            public function redact(mixed $payload): mixed
            {
                return \is_array($payload) ? array_map(fn(mixed $v): mixed => \is_array($v) ? array_diff_key($v, ['customerId' => true]) : $v, $payload) : $payload;
            }
        };
        $model = (new RunDashboard(new RenderingCatalog(), redactor: $masksCustomers))->build();

        self::assertStringNotContainsString('cus-42', $this->twig('en')->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', $model));
    }

    public function testTheHookableRendersTheDashboardFromTheHookContext(): void
    {
        // TwigHooks hands a hookable nothing but `hookable_metadata`: the page's variables travel
        // in its context (#383). A variable that does not make the trip is an empty dashboard.
        $model = (new RunDashboard(new RenderingCatalog()))->build();
        $metadata = new class ($model) {
            public object $context;

            /** @param array<string, mixed> $model */
            public function __construct(array $model)
            {
                $this->context = new class ($model) {
                    /** @param array<string, mixed> $model */
                    public function __construct(private readonly array $model) {}

                    /** @return array<string, mixed> */
                    public function all(): array
                    {
                        return $this->model;
                    }
                };
            }
        };

        $page = $this->twig('en')->render('@DurablePlugin/admin/dashboard/index/content/dashboard.html.twig', ['hookable_metadata' => $metadata]);

        self::assertStringContainsString('SendWelcomeEmail', $page);
    }

    /**
     * #264: the list is a page of its own, full width, and each run links to its own address.
     */
    public function testTheListPageIsTheListAloneAndLinksEachRunToItsAddress(): void
    {
        $model = (new RunDashboard(new RenderingCatalog()))->listing();
        $model['pagination']['isFirstPage'] = true;

        $page = $this->twig()->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', $model);

        self::assertStringContainsString('<div class="col-12">', $page);
        self::assertStringContainsString('href="/gplanchat_durable_plugin_admin_run_show?runId=run-1&amp;status=all', $page);
        self::assertStringNotContainsString('Run details', $page);
    }

    /**
     * #514: on Temporal the run id is the server's own; the link is the id the application started
     * the run with, the one `findRun()` finds by.
     */
    public function testARunLinksByTheIdTheApplicationStartedItWith(): void
    {
        $model = (new RunDashboard(new RenderingCatalog(executionId: 'order/42')))->listing();
        $model['pagination']['isFirstPage'] = true;

        $page = $this->twig()->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', $model);

        self::assertStringContainsString('href="/gplanchat_durable_plugin_admin_run_show?runId=order%2F42&amp;', $page);
        self::assertStringContainsString('<code>order/42</code>', $page);

        $run = (new RunDashboard(new RenderingCatalog(executionId: 'order/42')))->run('order/42');
        $page = $this->twig()->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', ['backend' => $run['backend'], 'selectedRun' => $run['run']]);

        self::assertStringContainsString('<code>order/42</code> <small class="text-secondary">Backend run run-1</small>', $page);
    }

    public function testTheRunPageUsesTheVocabularyOfTheOtherSurfaces(): void
    {
        // #821: Execution, History and Outcome, as on Filament, Magento and the profiler.
        $run = (new RunDashboard(new RenderingCatalog(executionId: 'order/42')))->run('order/42');
        $page = $this->twig()->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', ['backend' => $run['backend'], 'selectedRun' => $run['run']]);

        self::assertStringContainsString('Execution:', $page);
        self::assertStringNotContainsString('Run:', $page);
        self::assertStringContainsString('>History<', $page);
        self::assertStringContainsString('Outcome:', $page);
    }

    public function testTheRunPageSaysWhatARunWaitsOnAsTheListDoes(): void
    {
        // #822: the list carried the line, and the page of the run did not.
        $run = (new RunDashboard(new RenderingCatalog(executionId: 'order/42', waitingOn: 'signal approve')))->run('order/42');
        $page = $this->twig()->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', ['backend' => $run['backend'], 'selectedRun' => $run['run']]);

        self::assertStringContainsString('waiting on signal approve', $page);
    }

    public function testARunWithoutAWaitReasonHasNoWaitLineOnItsPage(): void
    {
        $run = (new RunDashboard(new RenderingCatalog(executionId: 'order/42')))->run('order/42');
        $page = $this->twig()->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', ['backend' => $run['backend'], 'selectedRun' => $run['run']]);

        self::assertStringNotContainsString('waiting on', $page);
    }

    public function testTheStartDateOfARunThatHasNoneIsADashAndNotABlankCell(): void
    {
        // The overview: a blank cell reads as a rendering that failed.
        $twig = $this->twig();

        self::assertSame('—', trim($twig->render('@DurablePlugin/admin/grid/field/date.html.twig', ['data' => null])));
        self::assertSame('2023-11-14 22:13:20', trim($twig->render('@DurablePlugin/admin/grid/field/date.html.twig', ['data' => new \DateTimeImmutable('@1700000000')])));
    }

    public function testAFrenchRunPageUsesTheSameWords(): void
    {
        $run = (new RunDashboard(new RenderingCatalog(executionId: 'order/42')))->run('order/42');
        $page = $this->twig('fr')->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', ['backend' => $run['backend'], 'selectedRun' => $run['run']]);

        self::assertStringContainsString('Exécution :', $page);
        self::assertStringContainsString('>Historique<', $page);
        self::assertStringContainsString('Exécution côté backend run-1', $page);
    }

    public function testTheGridBadgeOfARunIsSingularInFrench(): void
    {
        // The grid field is what a real Sylius install renders for each row.
        $badge = $this->twig('fr')->render('@DurablePlugin/admin/grid/field/status.html.twig', ['data' => 'completed']);

        self::assertStringContainsString('>TERMINÉE<', $badge);
        self::assertStringNotContainsString('TERMINÉES', $badge);
    }

    public function testAnOutcomeBadgeIsSingularInFrench(): void
    {
        // The plural label of a counter is wrong on the badge of a single run.
        $translator = new Translator('fr');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', \dirname(__DIR__, 2) . '/translations/durable.fr.xlf', 'fr', 'durable');

        self::assertSame(
            ['En cours', 'Terminée', 'En échec', 'Annulée', 'Poursuivie à neuf'],
            array_map(static fn(string $outcome): string => $translator->trans('outcome.' . $outcome, [], 'durable'), ['running', 'completed', 'failed', 'cancelled', 'continued_as_new']),
        );
    }

    public function testTheRunPageSaysWhereANexusOperationWaitsAndThatItIsInFlight(): void
    {
        // #671: a Nexus operation is the wait served by someone else; in flight is not a failure.
        $page = $this->renderNexus([new NexusOperationSummary('demo-shop-stock', 'stock', 'reserve', NexusOperationState::InFlight)]);

        foreach (['Nexus operations', '<code>demo-shop-stock</code>', '<td>stock</td>', '<td>reserve</td>', 'durable-nexus-state in_flight">in flight<'] as $shown) {
            self::assertStringContainsString($shown, $page);
        }
    }

    public function testEachSettledOutcomeIsShownAsItsOwnState(): void
    {
        $states = ['completed' => NexusOperationState::Completed, 'failed' => NexusOperationState::Failed, 'timed out' => NexusOperationState::TimedOut, 'cancelled' => NexusOperationState::Cancelled];
        $page = $this->renderNexus(array_map(static fn(NexusOperationState $state): NexusOperationSummary => new NexusOperationSummary('demo-business-billing', 'billing', 'charge', $state), array_values($states)));

        foreach ($states as $label => $state) {
            self::assertStringContainsString("durable-nexus-state {$state->value}\">{$label}<", $page);
        }
        self::assertStringNotContainsString('in flight', $page);
    }

    public function testTheNexusOperationsSpeakFrenchWhenTheAdminDoes(): void
    {
        $page = $this->renderNexus([new NexusOperationSummary('demo-shop-stock', 'stock', 'reserve', NexusOperationState::InFlight)], 'fr');

        self::assertStringContainsString('Opérations Nexus', $page);
        self::assertStringContainsString('>en cours<', $page);
    }

    /**
     * @param list<NexusOperationSummary> $operations
     */
    private function renderNexus(array $operations, string $locale = 'en'): string
    {
        $run = (new RunDashboard(new RenderingCatalog(nexusOperations: $operations)))->run('run-1');

        return $this->twig($locale)->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', ['backend' => $run['backend'], 'selectedRun' => $run['run']]);
    }

    public function testTheRunPageIsTheRunAloneWithAWayBackToItsList(): void
    {
        $model = (new RunDashboard(new RenderingCatalog()))->run('run-1');

        $page = $this->twig()->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', [
            'backend' => $model['backend'],
            'selectedRun' => $model['run'],
            'list' => ['status' => 'failed', 'cursor' => '', 'back' => ''],
        ]);

        self::assertStringContainsString('SendWelcomeEmail', $page);
        self::assertStringContainsString('href="/gplanchat_durable_plugin_admin_run_index?status=failed">Back to the runs</a>', $page);
        self::assertStringNotContainsString('On this page', $page, 'no counters: they count a list');
        self::assertStringNotContainsString('>Runs<', $page);
    }

    public function testAPageAfterTheFirstLeadsBackToTheFirstPage(): void
    {
        // #383: the list pages forward only; the way back is the first page, with the status kept.
        $page = $this->render(firstPage: false);

        self::assertStringContainsString('First page', $page);
        self::assertStringNotContainsString('Previous page', $page);
        self::assertStringNotContainsString('back=', $page);
    }

    public function testTheFirstPageOffersNoWayBack(): void
    {
        $page = $this->render();

        self::assertStringNotContainsString('First page', $page);
        self::assertStringNotContainsString('Previous page', $page);
    }

    /**
     * A missing worker fails nothing: executions stop at their first task of its kind. The page
     * names the role and the command, in `durable:worker`'s words, not the backend's.
     */
    public function testAWorkerThatStoppedPollingIsNamedWithTheCommandToStart(): void
    {
        $page = $this->renderWorkers([
            ['role' => 'workflow', 'pollers' => 2, 'polling' => true, 'error' => null, 'seconds' => 120],
            ['role' => 'activity', 'pollers' => 1, 'polling' => false, 'error' => null, 'seconds' => 120],
        ]);

        self::assertStringContainsString('durable:worker --role=activity', $page);
        self::assertStringContainsString('workflow worker is polling', $page);
        self::assertStringNotContainsString('--role=workflow', $page);
        self::assertStringContainsString('alert-danger', $page);
    }

    public function testAnUnansweredProbeBlamesNoWorker(): void
    {
        $page = $this->renderWorkers([['role' => 'activity', 'pollers' => 0, 'polling' => false, 'error' => 'deadline exceeded', 'seconds' => 120]], 'fr');

        self::assertStringContainsString('Impossible de demander au backend', $page);
        self::assertStringContainsString('deadline exceeded', $page);
        self::assertStringNotContainsString('--role=', $page);
        self::assertStringNotContainsString('alert-danger', $page);
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

    public function testARunNobodyPickedUpSaysItWaitsForAWorker(): void
    {
        $page = $this->render(waiting: true);

        self::assertStringContainsString('waiting for a worker · ', $page);
        self::assertStringContainsString('Waiting for a worker', $page, 'the page counts them, as it counts outcomes');
    }

    public function testARunAWorkerPickedUpSaysNothingOfTheKind(): void
    {
        self::assertStringNotContainsString('waiting for a worker', $this->render());
    }

    public function testASuspendedRunSaysWhatItWaitsOn(): void
    {
        // #324: the catalogue records the wait and the core words it; the list is where an
        // operator asks why a run is not moving.
        $page = $this->render(waitingOn: 'timer due at 2026-09-24T10:00:00+00:00');

        self::assertStringContainsString('waiting on timer due at 2026-09-24T10:00:00+00:00', $page);
    }

    public function testARunWithNoRecordedWaitSaysNothingOfTheKind(): void
    {
        self::assertStringNotContainsString('waiting on', $this->render());
    }

    public function testTheWholePageSpeaksFrenchWhenTheAdminDoes(): void
    {
        $page = $this->render(waiting: true, locale: 'fr');

        self::assertStringContainsString('Tableau de bord des workflows Durable', $page);
        // Every literal the template used to print in English (M28). What the model itself says
        // (backend health, durations) is the core's wording and is not the template's to translate.
        foreach (['Durable Workflow Dashboard', 'Observe workflow runs', 'Outcomes across', 'On this page', 'Waiting for a worker',
            '>Outcome<', '>Filter<', '>Runs<', 'Run details', 'Workflow:', 'Outcome:', 'No recorded history', 'Select a run',
            'Hatched:', 'Red: what failed', 'RUNNING', 'Continued as new', 'checked at'] as $english) {
            self::assertStringNotContainsString($english, $page);
        }
    }

    public function testEachEventSaysWhatHappenedToItsAction(): void
    {
        // #332: the scheduling and the start of SendWelcomeEmail carry the same label; the phase
        // is what tells them apart. The signal is its own action and has none.
        $page = $this->render();

        self::assertStringContainsString('<span class="badge durable-phase durable-phase--requested">requested</span>', $page);
        self::assertStringContainsString('<span class="badge durable-phase durable-phase--started">started</span>', $page);
        self::assertSame(2, substr_count($page, 'durable-phase--'));

        $french = $this->render(locale: 'fr');
        self::assertStringContainsString('durable-phase--requested">demandé</span>', $french);
        self::assertStringContainsString('durable-phase--started">pris en charge</span>', $french);
    }

    public function testEveryKeyHasAFrenchTranslation(): void
    {
        $catalogue = static fn(string $locale): array => (new XliffFileLoader())
            ->load(\dirname(__DIR__, 2) . "/translations/durable.$locale.xlf", $locale, 'durable')
            ->all('durable');

        self::assertSame(array_keys($catalogue('en')), array_keys($catalogue('fr')));
    }

    /** @param bool $firstPage what the controller says of the page: the first, or one after it */
    private function render(bool $ephemeral = false, bool $badPayload = false, bool $waiting = false, string $locale = 'en', bool $firstPage = true, bool $secrets = false, ?string $waitingOn = null): string
    {
        $catalog = new RenderingCatalog($ephemeral, $badPayload, $waiting, $secrets, waitingOn: $waitingOn);
        $model = (new RunDashboard($catalog))->build();
        $model['pagination']['isFirstPage'] = $firstPage;

        return $this->twig($locale)->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', $model);
    }

    /**
     * @param list<array{role: string, pollers: int, polling: bool, error: ?string, seconds: int}> $workers
     */
    private function renderWorkers(array $workers, string $locale = 'en'): string
    {
        $model = (new RunDashboard(new RenderingCatalog(false, false, false, false)))->build();
        $model['pagination']['isFirstPage'] = true;

        return $this->twig($locale)->render('@DurablePlugin/admin/dashboard/_dashboard.html.twig', $model + ['workers' => $workers]);
    }

    private function twig(string $locale = 'en'): Environment
    {
        $plugin = new FilesystemLoader([\dirname(__DIR__, 2) . '/templates'], null);
        $plugin->addPath(\dirname(__DIR__, 2) . '/templates', 'DurablePlugin');

        // The Sylius admin chrome is not installed here, and does not have to be: this test guards
        // the page, not the store.
        $sylius = new ArrayLoader([
            '@SyliusAdmin/shared/layout/base.html.twig' => '{% block title %}{% endblock %}{% block stylesheets %}{% endblock %}{% block body %}{% endblock %}',
            '@SyliusAdmin/shared/crud/common/sidebar.html.twig' => '',
            '@SyliusAdmin/shared/crud/common/navbar.html.twig' => '',
        ]);

        $twig = new Environment(new ChainLoader([$plugin, $sylius]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn(string $route, array $parameters = []): string => '/' . $route . '?' . http_build_query($parameters)));

        $translator = new Translator($locale);
        $translator->addLoader('xlf', new XliffFileLoader());
        foreach (['en', 'fr'] as $available) {
            $translator->addResource('xlf', \dirname(__DIR__, 2) . "/translations/durable.$available.xlf", $available, 'durable');
        }
        $twig->addExtension(new TranslationExtension($translator));

        return $twig;
    }
}

final class RenderingCatalog implements WorkflowRunCatalogInterface, NexusOperationCatalogInterface
{
    public function __construct(
        private readonly bool $ephemeral = false,
        private readonly bool $badPayload = false,
        private readonly bool $waiting = false,
        private readonly bool $secrets = false,
        private readonly ?string $waitingOn = null,
        private readonly ?string $executionId = null,
        /** @var list<NexusOperationSummary> */
        private readonly array $nexusOperations = [],
    ) {}

    public function canFilterRuns(?WorkflowRunFilter $filter = null): bool
    {
        return true;
    }

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20, ?WorkflowRunFilter $filter = null): WorkflowRunPage
    {
        if ($this->ephemeral) {
            return new WorkflowRunPage([]);
        }

        return new WorkflowRunPage([
            new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, new \DateTimeImmutable('@1700000000'), waitingForWorkerSince: $this->waiting ? new \DateTimeImmutable('@1700000000') : null, waitingOn: $this->waitingOn, executionId: $this->executionId),
        ], tellsWaitingForWorker: $this->waiting);
    }

    public function findRun(ExecutionId $executionId): ?WorkflowRunDescription
    {
        return array_values(array_filter($this->listRuns()->runs, static fn(WorkflowRunDescription $run): bool => $run->executionId === $executionId->toString()))[0] ?? null;
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
                    : ['payload' => ['customerId' => 'cus-42'] + ($this->secrets ? ['password' => 'hunter2', 'api_key' => 'sk-live-123'] : [])],
                'activity:act-1',
                phase: WorkflowRunEventPhase::Requested,
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
                phase: WorkflowRunEventPhase::Started,
            ),
            new WorkflowRunEvent(
                3,
                new \DateTimeImmutable('@1700000020'),
                WorkflowRunEventKind::Signal,
                'orderApproved',
            ),
        ];
    }

    public function readNexusOperations(WorkflowRunDescription $run): array
    {
        return $this->nexusOperations;
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
