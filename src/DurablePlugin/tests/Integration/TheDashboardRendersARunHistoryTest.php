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

    public function testTheActionsArePlacedInTimeAndNotMerelyStacked(): void
    {
        // Empiler des blocs répond « dans quel ordre », jamais « pendant combien de temps » — et la
        // seconde est la question qu'un exploitant devant une exécution lente vient poser.
        $page = $this->render();

        self::assertStringContainsString('durable-frieze', $page);
        // L'activité ouvre à 0 s et le signal tombe à 20 s sur une portée de 20 s : le repère du
        // signal est donc tout à droite. Un étalement par rang l'aurait mis au milieu.
        self::assertMatchesRegularExpression('/left: 100\.000%/', $page);
    }

    public function testWaitingToBePickedUpIsHatchedAndSaysSoOnHover(): void
    {
        $page = $this->render();

        self::assertStringContainsString('waiting', $page);
        self::assertStringContainsString('waiting to be picked up', $page, 'une hachure sans légende est une devinette');
    }

    public function testTheHatchingIsExplainedOnThePageAndNotOnlyOnHover(): void
    {
        // Survoler suppose de savoir qu'il y a quelque chose à survoler.
        $page = $this->render();

        self::assertStringContainsString('durable-frieze-key', $page);
    }

    public function testAPayloadWithABadByteStillUnfoldsOnWhatIsReadable(): void
    {
        // Sans tolérance, `json_encode` rendait `false` : le dépliant s'ouvrait sur du vide, et
        // c'est l'écran qu'un exploitant ouvre en dernier recours.
        $page = $this->render(badPayload: true);

        self::assertStringContainsString('<details>', $page);
        self::assertStringContainsString('ORD-7', $page);
    }

    public function testAnEventReadsAtTheSameMomentInTheFriezeAndInTheList(): void
    {
        // La frise compose son infobulle dans le cœur, avec le fuseau de l'événement ; le filtre
        // `date` de Twig, lui, applique celui du serveur. Sur une machine à Paris, le même
        // événement se lisait 22:13:20 au survol et 23:13:20 dans la ligne juste dessous — dans une
        // page dont toute la raison d'être est qu'un exploitant n'ait rien à convertir de tête.
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
        // Un intitulé « Total » sous lequel on lit vingt apprend à l'exploitant qu'une application
        // qui a enregistré cinq cents exécutions en a vingt. Ce que ces compteurs couvrent est la
        // page, parce que c'est ce que le catalogue a été interrogé de rendre.
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
            // Prise en charge dix secondes après la planification : les dix premières secondes sont
            // une file, pas du travail.
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
