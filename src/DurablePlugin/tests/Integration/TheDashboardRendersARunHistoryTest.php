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
 * Le gabarit rendu pour de vrai, sur une exécution qui a une histoire.
 *
 * Les autres assertions de ce dossier lisent le fichier ; celle-ci l'exécute. La différence n'est
 * pas cosmétique : la frise vient désormais du cœur, et le gabarit traverse `action.events`, puis
 * `mark.event.label` — un chemin qu'aucune lecture de texte n'éprouve. Une propriété mal nommée
 * dans cette chaîne ne casse rien à l'installation et rend une page vide en production, sur
 * précisément l'écran qu'un exploitant est venu regarder.
 */
final class TheDashboardRendersARunHistoryTest extends TestCase
{
    public function testTheHistoryOfTheSelectedRunReachesThePage(): void
    {
        $page = $this->render();

        self::assertStringContainsString('SendWelcomeEmail', $page);
        self::assertStringContainsString('orderApproved', $page, 'un signal est une ligne à lui seul');
        self::assertStringContainsString('#1', $page, 'chaque événement garde son rang');
    }

    public function testAnEventCarryingSomethingUnfoldsAndAnEmptyOneStaysALine(): void
    {
        // Un dépliant qui s'ouvre sur du vide se rouvre à chaque fois : c'est exactement ce qu'on
        // ne veut pas faire faire deux fois à quelqu'un qui cherche une panne.
        $page = $this->render();

        self::assertStringContainsString('<details>', $page);
        self::assertStringContainsString('cus-42', $page);
        self::assertSame(1, substr_count($page, '<details>'), 'un seul des deux événements a de quoi déplier');
    }

    public function testAnEphemeralJournalIsNeitherAFailureNorASuccess(): void
    {
        // Le troisième état : il répond, et sa réponse est vide par construction.
        $page = $this->render(ephemeral: true);

        self::assertStringContainsString('alert-info', $page);
        self::assertStringNotContainsString('alert-warning', $page);
        self::assertStringNotContainsString('alert-success', $page);
    }

    private function render(bool $ephemeral = false): string
    {
        $catalog = new RenderingCatalog($ephemeral);
        $model = (new RunDashboard($catalog))->build();

        return $this->twig()->render('@DurablePlugin/admin/dashboard/index.html.twig', $model);
    }

    private function twig(): Environment
    {
        $plugin = new FilesystemLoader([\dirname(__DIR__, 2) . '/Resources/views'], null);
        $plugin->addPath(\dirname(__DIR__, 2) . '/Resources/views', 'DurablePlugin');

        // Le châssis d'admin de Sylius n'est pas installé ici, et n'a pas à l'être : ce test garde
        // la page, pas la boutique.
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
                ['payload' => ['customerId' => 'cus-42']],
                'activity:act-1',
            ),
            new WorkflowRunEvent(
                2,
                new \DateTimeImmutable('@1700000010'),
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
