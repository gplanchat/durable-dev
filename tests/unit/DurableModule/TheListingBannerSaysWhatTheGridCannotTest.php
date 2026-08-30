<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;

/**
 * La bannière au-dessus de la grille, et pourquoi elle existe.
 *
 * ⚠ **Une grille vide ne dit rien toute seule.** Elle se lit pareil quand rien n'a tourné, quand la
 * grappe est tombée, et quand le journal ne survit pas à la requête qui rend la page. Cet écran ne
 * sondait pas : une grappe morte y rendait une grille vide et sereine, et l'exploitant en concluait
 * qu'il n'y avait rien à voir. Une grille n'a pas d'endroit où dire ça — la bannière, si.
 *
 * Comme pour le gabarit de détail, aucun outil de la CI n'analyse un `.phtml`.
 */
final class TheListingBannerSaysWhatTheGridCannotTest extends TestCase
{
    public function testAnUnreachableBackendIsNamedAndDated(): void
    {
        $page = $this->renderBanner($this->health(reachable: false));

        self::assertStringContainsString('message-error', $page);
        self::assertStringContainsString('Temporal', $page, 'l\'exploitant doit savoir quoi aller rallumer');
        self::assertStringContainsString('checked at', $page);
        self::assertStringNotContainsString('Outcomes across', $page, 'ne rien compter sur un backend muet');
    }

    public function testAJournalThatDiesWithTheRequestIsNeitherAFailureNorSilence(): void
    {
        $page = $this->renderBanner($this->health(ephemeral: true));

        self::assertStringContainsString('message-warning', $page);
        self::assertStringContainsString('the correct answer, not a failure', $page);
        self::assertStringContainsString('durable/temporal/dsn', $page, 'dire quoi configurer, pas seulement que c\'est vide');
        self::assertStringNotContainsString('message-error', $page);
    }

    public function testTheCountersNameTheirScopeRatherThanClaimingATotal(): void
    {
        // Un intitulé « total » sous lequel on lit vingt apprend à l'exploitant qu'une boutique qui
        // a enregistré cinq cents exécutions en a vingt.
        $page = $this->renderBanner($this->health(), runs: 3);

        self::assertStringContainsString('most recent runs this screen reads', $page);
        self::assertStringContainsString('Continued as new', $page, 'toutes les issues ont leur seau');
    }

    public function testAFullWindowAnnouncesItsCeiling(): void
    {
        // Une fenêtre bornée qui ne s'annonce pas se découvre par une exécution qui manque.
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
 * Ce que la bannière appelle sur son bloc, et rien de plus.
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
