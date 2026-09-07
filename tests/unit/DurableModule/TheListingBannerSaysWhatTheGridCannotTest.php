<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;

/**
 * The banner above the grid, and why it exists.
 *
 * ⚠ **An empty grid says nothing all by itself.** It reads the same when nothing has run, when the
 * cluster is down, and when the journal does not survive the request that renders the page. This
 * screen was not probing: a dead cluster rendered an empty and serene grid there, and the operator
 * concluded there was nothing to see. A grid has nowhere to say that — the banner does.
 *
 * As for the detail template, no tool in CI analyses a `.phtml`.
 */
final class TheListingBannerSaysWhatTheGridCannotTest extends TestCase
{
    public function testAnUnreachableBackendIsNamedAndDated(): void
    {
        $page = $this->renderBanner($this->health(reachable: false));

        self::assertStringContainsString('message-error', $page);
        self::assertStringContainsString('Temporal', $page, 'the operator must know what to go and switch back on');
        self::assertStringContainsString('checked at', $page);
        self::assertStringNotContainsString('Outcomes across', $page, 'count nothing on a mute backend');
    }

    public function testAJournalThatDiesWithTheRequestIsNeitherAFailureNorSilence(): void
    {
        $page = $this->renderBanner($this->health(ephemeral: true));

        self::assertStringContainsString('message-warning', $page);
        self::assertStringContainsString('the correct answer, not a failure', $page);
        self::assertStringContainsString('durable/temporal/dsn', $page, 'say what to configure, not only that it is empty');
        self::assertStringNotContainsString('message-error', $page);
    }

    public function testTheCountersNameTheirScopeRatherThanClaimingATotal(): void
    {
        // A "total" heading under which twenty is read teaches the operator that a shop which has
        // recorded five hundred executions has twenty.
        $page = $this->renderBanner($this->health(), runs: 3);

        self::assertStringContainsString('most recent runs this screen reads', $page);
        self::assertStringContainsString('Continued as new', $page, 'every outcome has its bucket');
    }

    public function testAFullWindowAnnouncesItsCeiling(): void
    {
        // A bounded window that does not announce itself gets discovered through an execution
        // that is missing.
        $page = $this->renderBanner($this->health(), runs: BannerBlockDouble::WINDOW);

        self::assertStringContainsString('Older ones are beyond what it can list or open', $page);
    }

    public function testAWindowWithRoomLeftDoesNotWarnAboutNothing(): void
    {
        $page = $this->renderBanner($this->health(), runs: 2);

        self::assertStringNotContainsString('Older ones are beyond', $page);
    }

    private function renderBanner(BackendHealth $health, int $runs = 0): string
    {
        require_once __DIR__ . '/Fixture/magento-template-globals.php';

        $block = new BannerBlockDouble($health, $runs);
        $escaper = new EscaperDouble();

        ob_start();

        try {
            require __DIR__ . '/../../../src/DurableModule/view/adminhtml/templates/process/notice.phtml';
        } finally {
            $page = ob_get_clean();
        }

        self::assertIsString($page);

        return $page;
    }

    private function health(bool $reachable = true, bool $ephemeral = false): BackendHealth
    {
        return new BackendHealth(
            'Temporal',
            $reachable,
            $reachable ? 'Connected to Temporal namespace "default".' : 'Temporal namespace "default" is unreachable: connection refused',
            new \DateTimeImmutable('@1700000000'),
            $ephemeral,
        );
    }
}

/**
 * What the banner calls on its block, and nothing more.
 */
final class BannerBlockDouble
{
    public const WINDOW = 200;

    public function __construct(
        private readonly BackendHealth $health,
        private readonly int $runs = 0,
    ) {}

    public function getHealth(): BackendHealth
    {
        return $this->health;
    }

    public function isReachable(): bool
    {
        return $this->health->reachable;
    }

    public function isEphemeral(): bool
    {
        return $this->health->ephemeral;
    }

    /**
     * @return array<string, int>
     */
    public function getCounters(): array
    {
        $described = [];
        for ($index = 0; $index < $this->runs; ++$index) {
            $described[] = new WorkflowRunDescription('run-' . $index, 'App\\OrderWorkflow', WorkflowRunStatus::Running);
        }

        return RunDashboard::outcomeCounters($described);
    }

    public function getWindow(): int
    {
        return self::WINDOW;
    }
}
